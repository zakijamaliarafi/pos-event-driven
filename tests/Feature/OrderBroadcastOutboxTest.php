<?php

use App\Domain\Events\DomainEvent;
use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Orders\OrderProjectionConsumer;
use App\Domain\Orders\SubmitOrder;
use App\Jobs\DeliverOrderBroadcast;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function submitBroadcastOrder(bool $customer = false): Order
{
    $product = Product::factory()->withRecipe(5)->create();

    return app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Broadcast customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ], deviceId: $customer ? (string) Str::uuid() : null);
}

function projectBroadcastOrder(Order $order): object
{
    $eventId = DB::table('domain_outbox')->where('aggregate_id', $order->id)->where('event_name', 'OrderSubmitted')->value('id');
    app(ProcessDomainEvent::class)->process($eventId);

    return DB::table('order_broadcast_outbox')->where('order_id', $order->id)->first();
}

function broadcastFactory(Closure $publish): Factory
{
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->andReturnUsing($publish);
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->with('reverb')->andReturn($broadcaster);

    return $factory;
}

beforeEach(function () {
    config(['broadcasting.default' => 'reverb']);
});

test('projection intent and consumer receipts commit together without contacting Redis or Reverb', function (bool $customer) {
    Queue::shouldReceive('connection')->never();
    $order = submitBroadcastOrder($customer);
    $intent = projectBroadcastOrder($order);
    $projection = DB::table('order_read_models')->where('order_id', $order->id)->first();

    expect($intent->projection_version)->toBe($projection->version)
        ->and($intent->broadcast_connection)->toBe('reverb')
        ->and(json_decode($intent->channels, true))->toBe($customer
            ? ['private-staff.orders', 'private-customer.order.'.$order->id]
            : ['private-staff.orders'])
        ->and(json_decode($intent->payload, true))->toEqual([
            'order_id' => $order->id, 'order_number' => $order->order_number, 'status' => $projection->status,
        ])
        ->and($intent->sent_at)->toBeNull()
        ->and(DB::table('domain_event_receipts')->where('event_id', $intent->source_event_id)->count())->toBe(4);
})->with([false, true]);

test('rollback removes projection intent and receipts alongside inventory effects', function () {
    $order = submitBroadcastOrder();
    $ingredient = $order->items()->first()->product->recipes()->first()->inventoryItem;

    expect(function () use ($order) {
        DB::transaction(function () use ($order) {
            projectBroadcastOrder($order);
            throw new RuntimeException('Rollback after projection');
        });
    })->toThrow(RuntimeException::class, 'Rollback after projection');

    expect(DB::table('order_read_models')->count())->toBe(0)
        ->and(DB::table('order_broadcast_outbox')->count())->toBe(0)
        ->and(DB::table('domain_event_receipts')->count())->toBe(0)
        ->and($ingredient->fresh()->reserved_stock)->toBe('0.00');
});

test('disabled broadcasting stores an explicit null driver without blocking projection', function () {
    config(['broadcasting.default' => null]);
    $intent = projectBroadcastOrder(submitBroadcastOrder());

    expect($intent->broadcast_connection)->toBe('null');
    (new DeliverOrderBroadcast($intent->id))->handle(app(Factory::class));
    expect(DB::table('order_broadcast_outbox')->where('id', $intent->id)->value('sent_at'))->not->toBeNull();
});

test('repeated events and current projections do not create additional intents', function () {
    $order = submitBroadcastOrder();
    $intent = projectBroadcastOrder($order);
    app(ProcessDomainEvent::class)->process($intent->source_event_id);
    $event = DomainEvent::fromRow(DB::table('domain_outbox')->where('id', $intent->source_event_id)->first());
    DB::transaction(fn () => app(OrderProjectionConsumer::class)->handle($event));

    expect(DB::table('order_broadcast_outbox')->count())->toBe(1);
});

test('delivery uses the stored connection channels and payload and skips sent or missing intents', function () {
    $order = submitBroadcastOrder(true);
    $intent = projectBroadcastOrder($order);
    $calls = 0;
    $factory = broadcastFactory(function ($channels, $name, $payload) use ($intent, &$calls) {
        $calls++;
        expect($channels)->toBe(json_decode($intent->channels, true))
            ->and($name)->toBe('order.changed')
            ->and($payload)->toBe(json_decode($intent->payload, true));
    });
    DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled', 'device_id' => null]);
    config(['broadcasting.default' => 'null']);
    $job = new DeliverOrderBroadcast($intent->id);
    $job->handle($factory);
    $job->handle($factory);
    (new DeliverOrderBroadcast((string) Str::uuid()))->handle($factory);
    $job->failed(new RuntimeException('Late failure from another delivery'));

    $sent = DB::table('order_broadcast_outbox')->where('id', $intent->id)->first();
    expect($calls)->toBe(1)->and($sent->sent_at)->not->toBeNull()->and($sent->last_error)->toBeNull();
});

test('publication errors remain durable and a successful retry clears them', function () {
    $intent = projectBroadcastOrder(submitBroadcastOrder());
    $job = new DeliverOrderBroadcast($intent->id);
    $factory = broadcastFactory(fn () => throw new RuntimeException('Reverb unavailable'));

    expect(fn () => $job->handle($factory))->toThrow(RuntimeException::class, 'Reverb unavailable');
    $pending = DB::table('order_broadcast_outbox')->where('id', $intent->id)->first();
    expect($pending->sent_at)->toBeNull()->and($pending->last_error)->toBe('Reverb unavailable');
    $job->failed(new RuntimeException('Attempts exhausted'));
    $job->handle(broadcastFactory(fn () => null));

    $sent = DB::table('order_broadcast_outbox')->where('id', $intent->id)->first();
    expect($sent->sent_at)->not->toBeNull()->and($sent->last_error)->toBeNull();
});

test('a failure after publication before its marker commits permits safe redelivery', function () {
    $intent = projectBroadcastOrder(submitBroadcastOrder());
    $calls = 0;
    $failMarker = true;
    $factory = broadcastFactory(function () use (&$calls) {
        $calls++;
    });
    DB::listen(function ($query) use (&$failMarker) {
        if ($failMarker && str_starts_with($query->sql, 'update `order_broadcast_outbox`') && str_contains($query->sql, '`sent_at`')) {
            $failMarker = false;
            throw new RuntimeException('Worker died before commit');
        }
    });
    $job = new DeliverOrderBroadcast($intent->id);

    expect(fn () => $job->handle($factory))->toThrow(RuntimeException::class, 'Worker died before commit');
    expect(DB::table('order_broadcast_outbox')->where('id', $intent->id)->value('sent_at'))->toBeNull();
    $job->handle($factory);

    expect($calls)->toBe(2)
        ->and(DB::table('order_broadcast_outbox')->where('id', $intent->id)->value('sent_at'))->not->toBeNull();
});

test('dispatcher orders and limits intents and recovers stale unsent deliveries', function () {
    Queue::fake();
    config(['pos.broadcast_queue' => 'broadcast-test']);
    $first = projectBroadcastOrder(submitBroadcastOrder());
    $second = projectBroadcastOrder(submitBroadcastOrder());
    $sent = projectBroadcastOrder(submitBroadcastOrder());
    DB::table('order_broadcast_outbox')->where('id', $sent->id)->update(['sent_at' => now()]);

    Artisan::call('orders:dispatch-broadcasts', ['--limit' => 1]);
    Queue::assertPushed(DeliverOrderBroadcast::class, fn ($job) => $job->intentId === $first->id && $job->connection === 'redis' && $job->queue === 'broadcast-test');
    expect(DB::table('order_broadcast_outbox')->where('id', $second->id)->value('dispatched_at'))->toBeNull();
    Artisan::call('orders:dispatch-broadcasts');
    Queue::assertPushed(DeliverOrderBroadcast::class, 2);
    Artisan::call('orders:dispatch-broadcasts');
    Queue::assertPushed(DeliverOrderBroadcast::class, 2);
    $this->travel(61)->seconds();
    Artisan::call('orders:dispatch-broadcasts');
    Queue::assertPushed(DeliverOrderBroadcast::class, 4);
    expect(DB::table('order_broadcast_outbox')->where('id', $first->id)->value('attempts'))->toBe(2);
});

test('enqueue failure leaves a committed projection eligible for recovery', function () {
    $intent = projectBroadcastOrder(submitBroadcastOrder());
    Queue::shouldReceive('connection')->with('redis')->once()->andThrow(new RuntimeException('Redis unavailable'));

    expect(fn () => Artisan::call('orders:dispatch-broadcasts'))->toThrow(RuntimeException::class, 'Redis unavailable');
    $pending = DB::table('order_broadcast_outbox')->where('id', $intent->id)->first();
    expect($pending->dispatched_at)->toBeNull()->and($pending->attempts)->toBe(0)
        ->and(DB::table('domain_event_receipts')->where('event_id', $intent->source_event_id)->count())->toBe(4)
        ->and(DB::table('order_read_models')->where('order_id', $intent->order_id)->exists())->toBeTrue();
});
