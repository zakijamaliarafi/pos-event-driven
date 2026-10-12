<?php

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
