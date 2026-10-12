<?php

use App\Domain\Events\DomainEventRecovery;
use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Inventory\InventoryEventConsumer;
use App\Domain\Orders\OrderNotificationConsumer;
use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\TransitionOrder;
use App\Jobs\DeliverDomainEvent;
use App\Jobs\DeliverOrderBroadcast;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'testing' || DB::selectOne('select database() as name')->name !== 'testing') {
        throw new RuntimeException('Worker recovery tests require the testing database.');
    }
});

test('broadcast delivery locks its intent across publication without locking unrelated intents', function () {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $firstId = (string) Str::uuid();
    $otherId = (string) Str::uuid();
    config(['database.connections.broadcast_probe' => config('database.connections.'.config('database.default'))]);
    $probe = DB::connection('broadcast_probe');

    try {
        foreach ([$firstId, $otherId] as $index => $id) {
            DB::table('order_broadcast_outbox')->insert([
                'id' => $id, 'source_event_id' => (string) Str::uuid(),
                'order_id' => $index + 1, 'projection_version' => 1,
                'broadcast_connection' => 'reverb', 'channels' => '["private-staff.orders"]',
                'payload' => '{}', 'created_at' => now(),
            ]);
        }

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')->once()->andReturnUsing(function () use ($probe, $firstId, $otherId) {
            $probe->beginTransaction();

            try {
                expect(fn () => $probe->table('order_broadcast_outbox')->where('id', $firstId)->lock('for update nowait')->first())
                    ->toThrow(QueryException::class);
                expect($probe->table('order_broadcast_outbox')->where('id', $otherId)->lock('for update nowait')->first()->id)
                    ->toBe($otherId);
            } finally {
                $probe->rollBack();
            }
        });
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('reverb')->once()->andReturn($broadcaster);
        $job = new DeliverOrderBroadcast($firstId);
        $job->handle($factory);
        $job->handle($factory);

        expect(DB::table('order_broadcast_outbox')->where('id', $firstId)->value('sent_at'))->not->toBeNull()
            ->and(DB::table('order_broadcast_outbox')->where('id', $otherId)->value('sent_at'))->toBeNull();
    } finally {
        DB::purge('broadcast_probe');
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
});

test('Redis broadcast delivery recovers after publication fails without repeating domain effects', function () {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $queue = 'pos-broadcast-recovery-'.Str::lower(Str::random(12));
    config(['pos.domain_queue' => $queue, 'pos.broadcast_queue' => $queue, 'broadcasting.default' => 'reverb']);

    try {
        $product = Product::factory()->withRecipe(2)->create();
        $ingredient = $product->recipes()->firstOrFail()->inventoryItem;
        $order = app(SubmitOrder::class)->handle([
            'order_type' => 'takeaway', 'customer_name' => 'Broadcast recovery',
            'cart' => [['id' => $product->id, 'qty' => 1]],
        ]);
        drainRecoveryQueue($queue);
        $receipts = DB::table('domain_event_receipts')->count();
        $fail = true;
        $publications = 0;
        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')->andReturnUsing(function () use (&$fail, &$publications) {
            if ($fail) {
                throw new RuntimeException('Reverb temporarily unavailable');
            }

            $publications++;
        });
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('reverb')->andReturn($broadcaster);
        app()->instance(Factory::class, $factory);

        Artisan::call('orders:dispatch-broadcasts');
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true]);
        expect(DB::table('order_broadcast_outbox')->whereNotNull('last_error')->count())->toBe(1)
            ->and(DB::table('order_broadcast_outbox')->whereNotNull('sent_at')->count())->toBe(0);

        // Lose the remaining Redis jobs as well: MySQL must remain sufficient for recovery.
        Queue::connection('redis')->clear($queue);
        $fail = false;
        $this->travel(61)->seconds();
        Artisan::call('orders:dispatch-broadcasts');
        $count = DB::table('order_broadcast_outbox')->count();

        for ($index = 0; $index < $count; $index++) {
            Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true]);
        }

        expect($publications)->toBe($count)
            ->and(DB::table('order_broadcast_outbox')->whereNull('sent_at')->count())->toBe(0)
            ->and(DB::table('order_broadcast_outbox')->whereNotNull('last_error')->count())->toBe(0)
            ->and(DB::table('domain_event_receipts')->count())->toBe($receipts)
            ->and($order->fresh()->status)->toBe('waiting_payment')
            ->and($ingredient->fresh()->current_stock)->toBe('2.00')
            ->and($ingredient->fresh()->reserved_stock)->toBe('1.00');
    } finally {
        $this->travelBack();
        Queue::connection('redis')->clear($queue);
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
});

test('Redis worker recovers the full order workflow after an outage without duplicate stock use', function () {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $queue = 'pos-recovery-'.Str::lower(Str::random(12));
    config(['pos.domain_queue' => $queue]);

    try {
        $product = Product::factory()->withRecipe(2)->create(['price' => 12000]);
        $ingredient = $product->recipes()->firstOrFail()->inventoryItem;
        $cashier = User::factory()->create();
        $order = app(SubmitOrder::class)->handle([
            'order_type' => 'dine_in',
            'table_number' => 2,
            'customer_name' => 'Recovery customer',
            'cart' => [['id' => $product->id, 'qty' => 1]],
        ]);

        Artisan::call('domain:dispatch-outbox');
        expect($order->fresh()->status)->toBe('awaiting_inventory')
            ->and(DB::table('domain_outbox')->whereNull('processed_at')->count())->toBe(1);

        drainRecoveryQueue($queue);
        expect($order->fresh()->status)->toBe('waiting_payment')
            ->and($ingredient->fresh()->reserved_stock)->toBe('1.00');

        app(RecordCashPayment::class)->handle($order->id, $cashier->id, 'recovery-cash');
        drainRecoveryQueue($queue);
        expect($order->fresh()->status)->toBe('pending');

        app(TransitionOrder::class)->startPreparation($order->id);
        drainRecoveryQueue($queue);
        app(TransitionOrder::class)->markReady($order->id);
        drainRecoveryQueue($queue);
        app(TransitionOrder::class)->complete($order->id);
        drainRecoveryQueue($queue);

        $submittedId = DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->value('id');
        DeliverDomainEvent::dispatch($submittedId)->onConnection('redis')->onQueue($queue);
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1]);

        expect(Order::findOrFail($order->id)->status)->toBe('completed')
            ->and($ingredient->fresh()->current_stock)->toBe('1.00')
            ->and($ingredient->fresh()->reserved_stock)->toBe('0.00')
            ->and(DB::table('order_read_models')->where('order_id', $order->id)->value('status'))->toBe('completed')
            ->and((float) DB::table('order_read_models')->where('order_id', $order->id)->value('total_amount'))->toBe(12000.0)
            ->and(DB::table('domain_outbox')->whereNull('processed_at')->count())->toBe(0);
    } finally {
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
});

test('Redis worker resumes a verified customer order before kitchen preparation', function () {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $queue = 'pos-recovery-'.Str::lower(Str::random(12));
    config(['pos.domain_queue' => $queue]);

    try {
        $product = Product::factory()->withRecipe(1)->create(['price' => 12000]);
        $ingredient = $product->recipes()->firstOrFail()->inventoryItem;
        $cashier = User::factory()->create();
        $order = app(SubmitOrder::class)->handle([
            'order_type' => 'dine_in', 'table_number' => 2, 'customer_name' => 'Recovery customer',
            'cart' => [['id' => $product->id, 'qty' => 1]],
        ], deviceId: (string) Str::uuid());

        drainRecoveryQueue($queue);
        expect($order->fresh()->status)->toBe('awaiting_verification')
            ->and($ingredient->fresh()->reserved_stock)->toBe('1.00');

        app(TransitionOrder::class)->verify($order->id);
        expect($order->fresh()->status)->toBe('verifying');
        drainRecoveryQueue($queue);
        expect($order->fresh()->status)->toBe('pending')
            ->and($ingredient->fresh()->current_stock)->toBe('0.00')
            ->and($ingredient->fresh()->reserved_stock)->toBe('0.00');

        app(TransitionOrder::class)->startPreparation($order->id);
        drainRecoveryQueue($queue);
        app(TransitionOrder::class)->markReady($order->id);
        drainRecoveryQueue($queue);
        app(TransitionOrder::class)->complete($order->id);
        drainRecoveryQueue($queue);
        expect($order->fresh()->status)->toBe('awaiting_payment');

        app(RecordCashPayment::class)->handle($order->id, $cashier->id, 'recovery-after-meal');
        drainRecoveryQueue($queue);
        expect($order->fresh()->status)->toBe('completed')
            ->and(DB::table('order_read_models')->where('order_id', $order->id)->value('paid_at'))->not->toBeNull()
            ->and(DB::table('domain_outbox')->whereNull('processed_at')->count())->toBe(0);
    } finally {
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
});

function drainRecoveryQueue(string $queue): void
{
    for ($pass = 0; $pass < 30; $pass++) {
        Artisan::call('domain:dispatch-outbox');

        if (DB::table('domain_outbox')->whereNull('processed_at')->doesntExist()) {
            return;
        }

        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);
    }

    throw new RuntimeException('The Redis worker did not drain the domain outbox.');
}

test('Redis domain jobs exhaust five attempts per cycle and stop until an operator resumes the event', function () {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $queue = 'pos-domain-failure-'.Str::lower(Str::random(12));
    config(['pos.domain_queue' => $queue]);
    $id = (string) Str::uuid();
    $this->freezeTime();

    try {
        DB::table('domain_outbox')->insert([
            'id' => $id, 'event_name' => 'OrderSubmitted', 'schema_version' => 1,
            'aggregate_type' => Order::class, 'aggregate_id' => 999999,
            'aggregate_version' => 1, 'correlation_id' => $id,
            'payload' => json_encode(['order_id' => 999999]), 'occurred_at' => now(),
        ]);

        foreach ([60, 300, null] as $cycle => $recoveryDelay) {
            Artisan::call('domain:dispatch-outbox');
            $deliveryId = DB::table('domain_outbox')->where('id', $id)->value('active_delivery_id');
            foreach ([1, 5, 15, 30, 0] as $attempt => $backoff) {
                Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true]);
                expect(DB::table('domain_outbox')->where('id', $id)->value('failed_cycles'))->toBe($attempt === 4 ? $cycle + 1 : $cycle);
                if ($backoff > 0) {
                    $this->travel($backoff)->seconds();
                }
            }
            expect(DB::table('failed_jobs')->where('queue', $queue)->count())->toBe($cycle + 1);
            if ($recoveryDelay !== null) {
                $this->travel($recoveryDelay - 1)->seconds();
                Artisan::call('domain:dispatch-outbox');
                expect(Queue::connection('redis')->size($queue))->toBe(0);
                $this->travel(1)->seconds();
            }
        }

        $this->travel(1)->day();
        Artisan::call('domain:dispatch-outbox');
        expect(Queue::connection('redis')->size($queue))->toBe(0)
            ->and(DB::table('domain_outbox')->where('id', $id)->value('quarantined_at'))->not->toBeNull();
        // Replaying the original failed Redis payload cannot bypass quarantine.
        $failed = DB::table('failed_jobs')->where('queue', $queue)->first();
        Artisan::call('queue:retry', ['id' => [$failed->uuid]]);
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true]);
        expect(DB::table('domain_outbox')->where('id', $id)->value('failed_cycles'))->toBe(3);

        // Repair the missing aggregate dependency, keeping the event payload unchanged.
        DB::table('orders')->insert([
            'id' => 999999, 'order_number' => 'RECOVERY-'.Str::uuid(), 'status' => 'cancelled',
            'customer_name' => 'Restored order', 'total_amount' => 0, 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::call('domain:retry-event', ['eventId' => $id, '--reason' => 'Restored missing order']);
        Artisan::call('domain:dispatch-outbox');
        expect(DB::table('domain_outbox')->where('id', $id)->value('active_delivery_id'))->not->toBe($deliveryId);
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true]);
        expect(DB::table('domain_outbox')->where('id', $id)->value('processed_at'))->not->toBeNull()
            ->and(DB::table('domain_outbox')->where('id', $id)->value('last_error'))->toBeNull()
            ->and(DB::table('domain_event_receipts')->where('event_id', $id)->count())->toBe(4);
    } finally {
        $this->travelBack();
        Queue::connection('redis')->clear($queue);
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
});

test('operator retry locks the event and rechecks quarantine after a competing retry', function () {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    config(['database.connections.domain_retry_probe' => config('database.connections.'.config('database.default'))]);
    $primary = DB::getDefaultConnection();
    $probe = DB::connection('domain_retry_probe');
    $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $id = (string) Str::uuid();
    $other = (string) Str::uuid();

    try {
        foreach ([$id, $other] as $index => $eventId) {
            DB::table('domain_outbox')->insert([
                'id' => $eventId, 'event_name' => 'RetryProbe', 'schema_version' => 1,
                'aggregate_type' => 'retry-probe', 'aggregate_id' => $index + 1,
                'aggregate_version' => 1, 'correlation_id' => $eventId, 'payload' => '{}',
                'occurred_at' => now(), 'failed_cycles' => 3, 'quarantined_at' => now(),
            ]);
        }
        DB::connection($primary)->beginTransaction();
        DB::connection($primary)->table('domain_outbox')->where('id', $id)->lockForUpdate()->first();
        DB::setDefaultConnection('domain_retry_probe');
        expect(fn () => app(DomainEventRecovery::class)->retry($id, 'Competing retry'))
            ->toThrow(QueryException::class);
        app(DomainEventRecovery::class)->retry($other, 'Unrelated retry');
        DB::setDefaultConnection($primary);
        DB::connection($primary)->rollBack();

        app(DomainEventRecovery::class)->retry($id, 'First successful retry');
        DB::setDefaultConnection('domain_retry_probe');
        expect(fn () => app(DomainEventRecovery::class)->retry($id, 'Late competing retry'))
            ->toThrow(InvalidArgumentException::class);
        expect($probe->table('domain_outbox')->where('id', $other)->value('quarantined_at'))->toBeNull();
    } finally {
        DB::setDefaultConnection($primary);
        if (DB::connection($primary)->transactionLevel() > 0) {
            DB::connection($primary)->rollBack();
        }
        DB::purge('domain_retry_probe');
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
});

test('delivery identity is rechecked between committed consumers and before final completion', function (string $boundary, int $receiptCount) {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $id = (string) Str::uuid();
    $oldDelivery = (string) Str::uuid();
    $newDelivery = (string) Str::uuid();
    $enabled = true;

    try {
        DB::table('domain_outbox')->insert([
            'id' => $id, 'event_name' => 'BoundaryProbe', 'schema_version' => 1,
            'aggregate_type' => 'boundary-probe', 'aggregate_id' => 1, 'aggregate_version' => 1,
            'correlation_id' => $id, 'payload' => '{}', 'occurred_at' => now(), 'active_delivery_id' => $oldDelivery,
        ]);
        DB::listen(function (QueryExecuted $query) use (&$enabled, $boundary, $id, $newDelivery): void {
            if (! $enabled || ! str_starts_with($query->sql, 'insert into `domain_event_receipts`')
                || ! in_array($boundary, $query->bindings, true)) {
                return;
            }
            $enabled = false;
            DB::afterCommit(fn () => DB::table('domain_outbox')->where('id', $id)->update(['active_delivery_id' => $newDelivery]));
        });
        app(ProcessDomainEvent::class)->process($id, $oldDelivery);
        expect(DB::table('domain_event_receipts')->where('event_id', $id)->count())->toBe($receiptCount)
            ->and(DB::table('domain_outbox')->where('id', $id)->value('processed_at'))->toBeNull();
        (new DeliverDomainEvent($id, $oldDelivery))->failed(new RuntimeException('Stale boundary callback'));
        expect(DB::table('domain_outbox')->where('id', $id)->value('failed_cycles'))->toBe(0);
        app(ProcessDomainEvent::class)->process($id, $newDelivery);
        expect(DB::table('domain_event_receipts')->where('event_id', $id)->count())->toBe(4)
            ->and(DB::table('domain_outbox')->where('id', $id)->value('processed_at'))->not->toBeNull();
    } finally {
        $enabled = false;
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
})->with([
    [InventoryEventConsumer::class, 1],
    [OrderNotificationConsumer::class, 4],
]);
