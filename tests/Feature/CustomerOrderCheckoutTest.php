<?php

use App\Domain\Orders\SubmitOrder;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('customer order calculates price on the server and emits an outbox event', function () {
    $product = Product::factory()->withStock(5)->create(['price' => 25000]);

    Livewire::test('pages::customer.order')
        ->set('tableNumber', 4)
        ->set('customerName', 'Customer')
        ->set('quantities', [$product->id => 2])
        ->call('submit')
        ->assertHasNoErrors();

    $order = Order::firstOrFail();
    expect($order->total_amount)->toBe('50000.00')
        ->and($order->status)->toBe('awaiting_inventory')
        ->and($order->expires_at)->toBeNull()
        ->and($order->items()->count())->toBe(1);
    $this->assertDatabaseHas('domain_outbox', ['event_name' => 'OrderSubmitted', 'aggregate_id' => $order->id]);
});

test('customer order rejects an empty cart', function () {
    Livewire::test('pages::customer.order')
        ->set('tableNumber', 4)
        ->set('customerName', 'Customer')
        ->call('submit')
        ->assertHasErrors('cart');

    expect(Order::count())->toBe(0);
});

test('customer cannot cancel an order from another device', function () {
    $product = Product::factory()->withStock(1)->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ], deviceId: (string) Str::uuid());

    expect(fn () => Livewire::withCookie('pos_device', (string) Str::uuid())
        ->test('pages::customer.order')->call('cancel', $order->id))
        ->toThrow(ModelNotFoundException::class);

    expect($order->fresh()->status)->toBe('awaiting_inventory');
});

test('customer can cancel their own unpaid order', function () {
    $product = Product::factory()->withStock(1)->create();
    $deviceId = (string) Str::uuid();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ], deviceId: $deviceId);

    Livewire::withCookie('pos_device', $deviceId)->test('pages::customer.order')
        ->call('cancel', $order->id)
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('cancelled');
});
