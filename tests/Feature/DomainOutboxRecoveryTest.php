<?php

use App\Domain\Events\DomainEventConsumers;
use App\Domain\Events\DomainEventRecovery;
use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Inventory\InventoryEventConsumer;
use App\Domain\Orders\OrderEventConsumer;
use App\Domain\Orders\OrderProjectionConsumer;
use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Jobs\DeliverDomainEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->freezeTime();
});

function recoveryEvent(int $version = 1, ?int $aggregateId = null): string
{
    $id = (string) Str::uuid();
    DB::table('domain_outbox')->insert([
        'id' => $id, 'event_name' => 'RecoveryProbe', 'schema_version' => 1,
        'aggregate_type' => 'recovery-probe', 'aggregate_id' => $aggregateId ?? random_int(1, 1000000000),
        'aggregate_version' => $version, 'correlation_id' => $id, 'payload' => '{}', 'occurred_at' => now(),
    ]);

    return $id;
}

function recoveryRow(string $id): object
{
    return DB::table('domain_outbox')->where('id', $id)->first();
}

function recoveryJob(string $id): DeliverDomainEvent
{
    Artisan::call('domain:dispatch-outbox');
    $deliveryId = recoveryRow($id)->active_delivery_id;
    expect($deliveryId)->not->toBeNull();

    return new DeliverDomainEvent($id, $deliveryId);
}

function runRecoveryJob(DeliverDomainEvent $job): void
{
    $job->handle(app(ProcessDomainEvent::class), app(DomainEventRecovery::class));
}

function recoveryReceipts(string $eventId, array $consumers = DomainEventConsumers::NAMES): void
{
    foreach ($consumers as $consumer) {
        DB::table('domain_event_receipts')->insert(['event_id' => $eventId, 'consumer' => $consumer, 'processed_at' => now()]);
    }
}

test('three exhausted cycles wait one then five minutes and quarantine without losing recovery history', function () {
    Log::spy();
    $id = recoveryEvent();
    $jobs = [];

    foreach ([60, 300, null] as $index => $delay) {
        $job = recoveryJob($id);
        $jobs[] = $job;
        $job->failed(new RuntimeException('Permanent consumer error'));
        $row = recoveryRow($id);
        expect($row->failed_cycles)->toBe($index + 1)
            ->and($row->active_delivery_id)->toBeNull()
            ->and($row->last_failed_consumer)->toBe(InventoryEventConsumer::class)
            ->and($row->last_error)->toBe('Permanent consumer error');

        // A duplicate job's terminal callback cannot consume a second cycle.
        $job->failed(new RuntimeException('Late duplicate error'));
        expect(recoveryRow($id)->failed_cycles)->toBe($index + 1)
            ->and(recoveryRow($id)->last_error)->toBe('Permanent consumer error');

        if ($delay !== null) {
            expect($row->next_attempt_at)->toBe(now()->addSeconds($delay)->toDateTimeString());
            $this->travel($delay - 1)->seconds();
            Artisan::call('domain:dispatch-outbox');
            runRecoveryJob($job);
            app(ProcessDomainEvent::class)->process($id);
            expect(recoveryRow($id)->attempts)->toBe($index + 1)
                ->and(DB::table('domain_event_receipts')->count())->toBe(0);
            $this->travel(1)->seconds();
        }
    }

    expect(recoveryRow($id)->quarantined_at)->not->toBeNull();
    $this->travel(2)->days();
    Artisan::call('domain:dispatch-outbox');
    foreach ($jobs as $job) {
        runRecoveryJob($job);
    }
    app(ProcessDomainEvent::class)->process($id);
    expect(recoveryRow($id)->attempts)->toBe(3)
        ->and(recoveryRow($id)->processed_at)->toBeNull()
        ->and(DB::table('domain_event_receipts')->count())->toBe(0);
    Log::shouldHaveReceived('warning')->times(3);
    Log::shouldHaveReceived('error')->once();
});

test('lost job redispatch reuses the delivery ID without consuming failure cycles', function () {
    $id = recoveryEvent();
    $first = recoveryJob($id);
    $this->travel(61)->seconds();
    $second = recoveryJob($id);
    expect($second->deliveryId)->toBe($first->deliveryId)
        ->and(recoveryRow($id)->attempts)->toBe(2)
        ->and(recoveryRow($id)->failed_cycles)->toBe(0);
    runRecoveryJob($second);
    runRecoveryJob($first);
    $first->failed(new RuntimeException('Stale failure after success'));
    expect(recoveryRow($id)->processed_at)->not->toBeNull()
        ->and(recoveryRow($id)->last_error)->toBeNull()
        ->and(DB::table('domain_event_receipts')->count())->toBe(4);
});

test('enqueue failure rolls back dispatch bookkeeping for both initial and lost deliveries', function (bool $redispatch) {
    $id = recoveryEvent();
    if ($redispatch) {
        recoveryJob($id);
        $this->travel(61)->seconds();
    }
    $before = recoveryRow($id);
    Queue::swap(Mockery::mock(Factory::class, function ($mock) {
        $mock->shouldReceive('connection')->with('redis')->andThrow(new RuntimeException('Redis unavailable'));
    }));

    expect(fn () => Artisan::call('domain:dispatch-outbox'))->toThrow(RuntimeException::class, 'Redis unavailable');
    expect(recoveryRow($id))->toEqual($before);
})->with([false, true]);

test('ordering dependencies do not consume cycles and unblock per consumer while other aggregates continue', function () {
    $previous = recoveryEvent(1, 123);
    $id = recoveryEvent(2, 123);
    $other = recoveryEvent();
    $job = recoveryJob($id);
    runRecoveryJob($job);
    expect(recoveryRow($id)->failed_cycles)->toBe(0)
        ->and(recoveryRow($id)->blocked_event_id)->toBe($previous)
        ->and(recoveryRow($id)->blocked_consumer)->toBe(InventoryEventConsumer::class)
        ->and(recoveryRow($id)->last_error)->toBeNull();
    $job->failed(new RuntimeException('Obsolete blocked delivery'));
    Artisan::call('domain:dispatch-outbox');
    expect(recoveryRow($id)->attempts)->toBe(1);
    runRecoveryJob(new DeliverDomainEvent($other, recoveryRow($other)->active_delivery_id));
    expect(recoveryRow($other)->processed_at)->not->toBeNull();

    recoveryReceipts($previous, [InventoryEventConsumer::class]);
    runRecoveryJob(recoveryJob($id));
    expect(recoveryRow($previous)->processed_at)->toBeNull()
        ->and(recoveryRow($id)->blocked_consumer)->toBe(OrderEventConsumer::class)
        ->and(DB::table('domain_event_receipts')->where('event_id', $id)->pluck('consumer')->all())->toBe([InventoryEventConsumer::class]);

    recoveryReceipts($previous, array_values(array_diff(DomainEventConsumers::NAMES, [InventoryEventConsumer::class])));
    runRecoveryJob(recoveryJob($id));
    expect(recoveryRow($id)->processed_at)->not->toBeNull()
        ->and(recoveryRow($id)->failed_cycles)->toBe(0)
        ->and(recoveryRow($id)->blocked_event_id)->toBeNull();
});

test('manual retry preserves receipts and error history and invalidates all earlier deliveries', function () {
    Log::spy();
    $id = recoveryEvent();
    $old = recoveryJob($id);
    recoveryReceipts($id, [InventoryEventConsumer::class]);
    DB::table('domain_outbox')->where('id', $id)->update([
        'failed_cycles' => 3, 'quarantined_at' => now(), 'last_error' => 'Repair required',
        'last_failed_at' => now(), 'last_failed_consumer' => OrderEventConsumer::class,
    ]);

    $this->artisan('domain:retry-event', ['eventId' => $id, '--reason' => 'Fixed payload handler'])->assertSuccessful();
    expect(recoveryRow($id)->failed_cycles)->toBe(0)
        ->and(recoveryRow($id)->last_error)->toBe('Repair required')
        ->and(DB::table('domain_event_receipts')->count())->toBe(1);
    runRecoveryJob($old);
    $old->failed(new RuntimeException('Stale callback'));
    $new = recoveryJob($id);
    expect($new->deliveryId)->not->toBe($old->deliveryId);
    $old->failed(new RuntimeException('Stale callback after new cycle'));
    runRecoveryJob($new);
    expect(recoveryRow($id)->processed_at)->not->toBeNull()
        ->and(recoveryRow($id)->last_error)->toBeNull()
        ->and(recoveryRow($id)->last_failed_at)->toBeNull()
        ->and(recoveryRow($id)->last_failed_consumer)->toBeNull()
        ->and(DB::table('domain_event_receipts')->count())->toBe(4);
    Log::shouldHaveReceived('notice')->once()->withArgs(fn ($message, $context) => $context['reason'] === 'Fixed payload handler');
});

test('terminal failure finalizes an event whose consumers already committed', function () {
    $id = recoveryEvent();
    $job = recoveryJob($id);
    recoveryReceipts($id);
    DB::table('domain_outbox')->where('id', $id)->update(['last_error' => 'Final marker failed']);
    $job->failed(new RuntimeException('Final marker failed'));
    expect(recoveryRow($id)->processed_at)->not->toBeNull()
        ->and(recoveryRow($id)->failed_cycles)->toBe(0)
        ->and(recoveryRow($id)->last_error)->toBeNull();
});

test('partial inventory effects survive quarantine and resume without duplicate stock or broadcast intents', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $ingredient = $product->recipes()->first()->inventoryItem;
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Partial recovery', 'cart' => [['id' => $product->id, 'qty' => 1]],
    ], idempotencyKey: (string) Str::uuid());
    $id = DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->value('id');
    // A projection insert fails after inventory and order consumers have committed.
    config(['broadcasting.default' => str_repeat('x', 51)]);
    foreach ([60, 300, null] as $delay) {
        $job = recoveryJob($id);
        expect(fn () => runRecoveryJob($job))->toThrow(QueryException::class);
        expect(recoveryRow($id)->last_failed_consumer)->toBe(OrderProjectionConsumer::class)
            ->and($ingredient->fresh()->reserved_stock)->toBe('1.00')
            ->and(DB::table('stock_reservations')->count())->toBe(1)
            ->and(DB::table('domain_outbox')->where('event_name', 'InventoryReserved')->count())->toBe(1);
        $job->failed(new RuntimeException('Projection insert failed'));
        if ($delay !== null) {
            $this->travel($delay)->seconds();
        }
    }
    expect(recoveryRow($id)->quarantined_at)->not->toBeNull()
        ->and(DB::table('domain_event_receipts')->where('event_id', $id)->count())->toBe(2)
        ->and(DB::table('order_broadcast_outbox')->count())->toBe(0);
    config(['broadcasting.default' => null]);
    $this->artisan('domain:retry-event', ['eventId' => $id, '--reason' => 'Corrected broadcaster configuration'])->assertSuccessful();
    $job = recoveryJob($id);
    runRecoveryJob($job);
    runRecoveryJob($job);
    expect(recoveryRow($id)->processed_at)->not->toBeNull()
        ->and($ingredient->fresh()->reserved_stock)->toBe('1.00')
        ->and(DB::table('stock_reservations')->count())->toBe(1)
        ->and(DB::table('cash_payments')->count())->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0)
        ->and(DB::table('order_broadcast_outbox')->count())->toBe(1);
});

test('manual retry rejects missing reasons and nonquarantined missing or processed events', function () {
    $id = recoveryEvent();
    foreach ([null, '', '   '] as $reason) {
        $this->artisan('domain:retry-event', ['eventId' => $id, '--reason' => $reason])->assertFailed();
    }
    $this->artisan('domain:retry-event', ['eventId' => $id, '--reason' => 'Repair'])->assertFailed();
    $this->artisan('domain:retry-event', ['eventId' => (string) Str::uuid(), '--reason' => 'Repair'])->assertFailed();
    runRecoveryJob(recoveryJob($id));
    $this->artisan('domain:retry-event', ['eventId' => $id, '--reason' => 'Repair'])->assertFailed();
});

test('a second operator retry is rejected after the first has scheduled recovery', function () {
    $id = recoveryEvent();
    DB::table('domain_outbox')->where('id', $id)->update(['quarantined_at' => now(), 'failed_cycles' => 3]);
    $this->artisan('domain:retry-event', ['eventId' => $id, '--reason' => 'Repair'])->assertSuccessful();
    $before = recoveryRow($id);
    $this->artisan('domain:retry-event', ['eventId' => $id, '--reason' => 'Duplicate request'])->assertFailed();
    expect(recoveryRow($id))->toEqual($before);
});

test('status JSON exposes filtered recovery details receipts and global summary ages', function () {
    $pending = recoveryEvent();
    $retrying = recoveryEvent();
    recoveryJob($retrying)->failed(new RuntimeException('Temporary error'));
    $quarantined = recoveryEvent();
    DB::table('domain_outbox')->where('id', $quarantined)->update(['quarantined_at' => now(), 'failed_cycles' => 3]);
    $blocked = recoveryEvent();
    DB::table('domain_outbox')->where('id', $blocked)->update([
        'blocked_event_id' => $quarantined, 'blocked_consumer' => InventoryEventConsumer::class,
    ]);
    $processed = recoveryEvent();
    runRecoveryJob(recoveryJob($processed));
    recoveryReceipts($quarantined, [InventoryEventConsumer::class]);
    $this->travel(120)->seconds();

    Artisan::call('domain:outbox-status', ['--status' => 'quarantined', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($output['summary']['counts'])->toBe(['pending' => 2, 'retrying' => 1, 'blocked' => 0, 'quarantined' => 1, 'processed' => 1])
        ->and($output['summary']['oldest_pending_age_seconds'])->toBe(120)
        ->and($output['summary']['oldest_quarantined_age_seconds'])->toBe(120)
        ->and($output['events'])->toHaveCount(1)
        ->and($output['events'][0]['id'])->toBe($quarantined)
        ->and($output['events'][0]['completed_consumers'])->toBe([InventoryEventConsumer::class])
        ->and($output['events'][0]['missing_consumers'])->toHaveCount(3);
    foreach (['pending', 'retrying', 'blocked', 'quarantined', 'processed'] as $state) {
        $this->artisan('domain:outbox-status', ['--status' => $state, '--json' => true])->assertSuccessful();
    }
    $this->artisan('domain:outbox-status', ['eventId' => $pending])->expectsOutputToContain('pending')->assertSuccessful();
});

test('status command validates filters limits and event identifiers', function () {
    $this->artisan('domain:outbox-status', ['--status' => 'invalid'])->assertFailed();
    foreach (['0', '-1', '1001', 'abc', '1.5'] as $limit) {
        $this->artisan('domain:outbox-status', ['--limit' => $limit])->assertFailed();
    }
    $this->artisan('domain:outbox-status', ['eventId' => (string) Str::uuid()])->assertFailed();
});

test('legacy queued jobs exit safely and old pending rows remain recoverable', function () {
    $id = recoveryEvent();
    DB::table('domain_outbox')->where('id', $id)->update([
        'dispatched_at' => now()->subMinutes(2), 'attempts' => 65535, 'last_error' => 'Historical failure',
    ]);
    $legacy = new DeliverDomainEvent($id);
    unset($legacy->deliveryId);
    $legacy = unserialize(serialize($legacy));
    expect($legacy->deliveryId)->toBeNull();
    runRecoveryJob($legacy);
    $legacy->failed(new RuntimeException('Legacy callback'));
    expect(recoveryRow($id)->last_error)->toBe('Historical failure')
        ->and(recoveryRow($id)->failed_cycles)->toBe(0);
    $job = recoveryJob($id);
    expect(recoveryRow($id)->attempts)->toBe(65536);
    runRecoveryJob($job);
    expect(recoveryRow($id)->processed_at)->not->toBeNull();
});

test('invalid retry configuration cannot silently disable recovery or create rapid loops', function (array $settings) {
    config($settings);
    expect(fn () => Artisan::call('domain:dispatch-outbox'))->toThrow(InvalidArgumentException::class);
})->with([
    [['pos.domain_max_delivery_cycles' => 0]],
    [['pos.domain_max_delivery_cycles' => '3']],
    [['pos.domain_recovery_delays' => [0, 300]]],
    [['pos.domain_recovery_delays' => [60]]],
    [['pos.domain_recovery_delays' => [300, 60]]],
    [['pos.domain_recovery_delays' => [60, 86401]]],
    [['queue.connections.redis.retry_after' => 30]],
]);

test('payment handoff recovers without duplicate cash payment stock use or kitchen notifications', function () {
    $product = Product::factory()->withRecipe(5)->create(['price' => 12000]);
    $ingredient = $product->recipes()->first()->inventoryItem;
    $cashier = User::factory()->create();
    Role::findOrCreate('kitchen', 'web');
    User::factory()->create()->assignRole('kitchen');
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Payment recovery', 'cart' => [['id' => $product->id, 'qty' => 1]],
    ], idempotencyKey: (string) Str::uuid());
    for ($pass = 0; $pass < 5; $pass++) {
        foreach (DB::table('domain_outbox')->whereNull('processed_at')->orderBy('sequence')->pluck('id') as $eventId) {
            app(ProcessDomainEvent::class)->process($eventId);
        }
    }
    app(RecordCashPayment::class)->handle($order->id, $cashier->id, 'recovery-payment-key');
    $id = DB::table('domain_outbox')->where('event_name', 'CashPaymentRecorded')->value('id');
    config(['broadcasting.default' => str_repeat('x', 51)]);
    foreach ([60, 300, null] as $delay) {
        $job = recoveryJob($id);
        expect(fn () => runRecoveryJob($job))->toThrow(QueryException::class);
        $job->failed(new RuntimeException('Projection failure after payment'));
        if ($delay !== null) {
            $this->travel($delay)->seconds();
        }
    }
    config(['broadcasting.default' => null]);
    $this->artisan('domain:retry-event', ['eventId' => $id, '--reason' => 'Repaired projection configuration'])->assertSuccessful();
    for ($pass = 0; $pass < 5; $pass++) {
        foreach (DB::table('domain_outbox')->whereNull('processed_at')->orderBy('sequence')->pluck('id') as $eventId) {
            runRecoveryJob(recoveryJob($eventId));
        }
    }
    app(RecordCashPayment::class)->handle($order->id, $cashier->id, 'recovery-payment-key');
    runRecoveryJob($job);
    expect($order->fresh()->status)->toBe('pending')
        ->and(DB::table('cash_payments')->count())->toBe(1)
        ->and((float) DB::table('cash_payments')->value('amount'))->toBe(12000.0)
        ->and(DB::table('domain_outbox')->where('event_name', 'OrderPaid')->count())->toBe(1)
        ->and(DB::table('notifications')->count())->toBe(1)
        ->and($ingredient->fresh()->current_stock)->toBe('5.00')
        ->and($ingredient->fresh()->reserved_stock)->toBe('1.00')
        ->and(DB::table('domain_outbox')->whereNull('processed_at')->count())->toBe(0);
});
