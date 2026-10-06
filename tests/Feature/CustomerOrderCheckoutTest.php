<?php

use App\Domain\Orders\SubmitOrder;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('customer order calculates price on the server and emits an outbox event', function () {
    $product = Product::factory()->withRecipe(5)->create(['price' => 25000]);

    Livewire::test('pages::customer.order')
        ->set('tableNumber', 4)
        ->set('customerName', 'Customer')
        ->set('quantities', [$product->id => 2])
        ->call('submit')
        ->assertHasNoErrors();

    $order = Order::firstOrFail();
    expect($order->total_amount)->toBe('50000.00')
        ->and($order->order_number)->toMatch('/^OTP-[A-Z0-9]{12}$/')
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
        ->assertHasErrors('cart')
        ->assertSee('Pilih setidaknya satu produk.');

    expect(Order::count())->toBe(0);
});

test('customer cannot cancel an order from another device', function () {
    $product = Product::factory()->withRecipe(1)->create();
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
    $product = Product::factory()->withRecipe(1)->create();
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

test('customer menu combines category and name filters without changing the cart', function () {
    $drinks = Category::factory()->create(['name' => 'Minuman']);
    $food = Category::factory()->create(['name' => 'Makanan']);
    $coffee = Product::factory()->withRecipe(5)->create(['name' => 'Kopi susu', 'category_id' => $drinks->id]);
    Product::factory()->withRecipe(5)->create(['name' => 'Teh', 'category_id' => $drinks->id]);
    Product::factory()->withRecipe(5)->create(['name' => 'Tahu susu', 'category_id' => $food->id]);

    Livewire::test('pages::customer.order')
        ->call('increment', $coffee->id)
        ->set('search', 'SUSU')
        ->set('categoryId', (string) $drinks->id)
        ->assertViewHas('products', fn ($rows) => $rows->modelKeys() === [$coffee->id])
        ->assertSet('quantities', [$coffee->id => 1])
        ->set('search', 'Tidak ada')
        ->assertSee('Menu tidak ditemukan')
        ->assertViewHas('cartRows', fn ($rows) => $rows->first()['name'] === 'Kopi susu')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('categoryId', '')
        ->assertViewHas('products', fn ($rows) => $rows->count() === 3)
        ->assertSet('quantities', [$coffee->id => 1]);
});

test('customer menu has distinct empty and unavailable recipe states and image fallbacks', function () {
    Product::factory()->create(['name' => 'Tanpa resep']);
    Product::factory()->withRecipe(0)->create(['name' => 'Bahan habis']);
    $page = Livewire::test('pages::customer.order')
        ->assertSee('Belum ada produk yang tersedia.')
        ->assertDontSee('Tanpa resep')
        ->assertDontSee('Bahan habis')
        ->assertDontSee('Reset pencarian');

    Product::factory()->withRecipe(3)->create(['name' => 'Foto kosong', 'image_url' => null]);
    Product::factory()->withRecipe(3)->create(['name' => 'Foto rusak', 'image_url' => 'products/missing.jpg']);
    $page->call('refreshOrders')->assertSee('Foto kosong')->assertSee('Foto rusak')
        ->assertSee('data-customer-image-fallback', false)->assertSee('x-on:error="broken = true"', false);
});

test('quantity controls respect portions and the command limit and remove the last item', function (int $stock, int $expected) {
    $product = Product::factory()->withRecipe($stock)->create(['price' => 15000]);
    $page = Livewire::test('pages::customer.order')->set('quantities', [$product->id => $expected - 1]);
    $page->call('increment', $product->id)->call('increment', $product->id)
        ->assertSet('quantities', [$product->id => $expected])
        ->assertViewHas('cartQuantity', $expected)
        ->assertViewHas('cartTotal', $expected * 1500000);
    $page->set('quantities', [$product->id => 1])->call('decrement', $product->id)
        ->assertSet('quantities', [])->assertViewHas('cartBlocked', true);
})->with([[3, 3], [150, 100]]);

test('checkout rejects malformed quantities before any integer conversion', function (mixed $quantity) {
    $product = Product::factory()->withRecipe(200)->create();
    Livewire::test('pages::customer.order')
        ->set('tableNumber', '4')->set('customerName', 'Customer')
        ->set('quantities', [$product->id => $quantity])->call('submit')
        ->assertHasErrors('cart')->assertDispatched('customer-checkout-invalid')
        ->assertSet('quantities', [$product->id => $quantity]);
    expect(Order::count())->toBe(0);
})->with([1.5, '1.5', 'abc', -1, 101, true, [[2]]]);

test('checkout preserves unavailable selections for correction', function (string $change) {
    $product = Product::factory()->withRecipe(5)->create(['name' => 'Pilihan lama']);
    $page = Livewire::test('pages::customer.order')->call('increment', $product->id)
        ->set('tableNumber', '4')->set('customerName', 'Customer');

    if ($change === 'deleted') {
        $product->delete();
    } elseif ($change === 'recipe') {
        $product->recipes()->delete();
    } else {
        $product->recipes()->firstOrFail()->inventoryItem->update(['current_stock' => 0]);
    }

    $page->call('refreshOrders')
        ->assertSet('quantities', [$product->id => 1])
        ->assertViewHas('products', fn ($rows) => $rows->isEmpty())
        ->assertViewHas('cartRows', fn ($rows) => $rows->first()['invalid'] && $rows->first()['name'] === 'Pilihan lama')
        ->assertViewHas('cartBlocked', true)
        ->call('submit')->assertHasErrors('cart')
        ->assertSet('customerName', 'Customer')
        ->call('remove', (string) $product->id)->assertSet('quantities', []);
    expect(Order::count())->toBe(0);
})->with(['deleted', 'recipe', 'depleted']);

test('refresh does not silently reduce quantities when available stock falls', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $page = Livewire::test('pages::customer.order')->set('quantities', [$product->id => 3]);
    $product->recipes()->firstOrFail()->inventoryItem->update(['current_stock' => 2]);

    $page->call('refreshOrders')->assertSet('quantities', [$product->id => 3])
        ->assertViewHas('cartBlocked', true)
        ->call('decrement', $product->id)->assertSet('quantities', [$product->id => 2])
        ->assertViewHas('cartBlocked', false);
});

test('cart prices are refreshed from the server and retained as historical order amounts', function () {
    $product = Product::factory()->withRecipe(5)->create(['price' => 15000.25]);
    $page = Livewire::test('pages::customer.order')->set('quantities', [$product->id => 2])
        ->assertViewHas('cartTotal', 3000050);
    $product->update(['price' => 16000.50]);
    $page->call('refreshOrders')->assertViewHas('cartTotal', 3200100)
        ->set('tableNumber', '5')->set('customerName', 'Customer')->call('submit')->assertHasNoErrors();
    $order = Order::firstOrFail();
    $product->update(['price' => 17000]);

    expect($order->fresh()->total_amount)->toBe('32001.00')
        ->and($order->items->first()->unit_price)->toBe('16000.50');
});

test('table links prefill a locked table and ignore editable table state at checkout', function () {
    $product = Product::factory()->withRecipe(5)->create();
    Livewire::withQueryParams(['table' => '10'])->test('pages::customer.order')
        ->assertSet('linkedTableNumber', 10)->assertSee('Meja 10')
        ->set('tableNumber', '99')->set('customerName', 'Customer')
        ->set('quantities', [$product->id => 1])->call('submit')->assertHasNoErrors();
    expect(Order::firstOrFail()->table_number)->toBe(10);
});

test('invalid table links require manual entry rather than table one', function (mixed $table) {
    $product = Product::factory()->withRecipe(5)->create();
    Livewire::withQueryParams(['table' => $table])->test('pages::customer.order')
        ->assertSet('linkedTableNumber', null)->assertSet('tableNumber', '')->assertSee('Pilih meja')
        ->set('customerName', 'Customer')->set('quantities', [$product->id => 1])
        ->call('submit')->assertHasErrors('table_number')
        ->set('tableNumber', '7')->call('submit')->assertHasNoErrors();
    expect(Order::firstOrFail()->table_number)->toBe(7);
})->with(['', '0', '-1', '1.5', 'x', '2147483648', [['10']]]);

test('customer identity and linked table properties cannot be tampered with', function (string $property, mixed $value) {
    Livewire::withQueryParams(['table' => '10'])->test('pages::customer.order')->set($property, $value);
})->with([
    ['linkedTableNumber', 99],
    ['deviceId', (string) Str::uuid()],
    ['submittedOrderId', 99],
])->throws(CannotUpdateLockedPropertyException::class);

test('closing and refreshing preserve checkout drafts and successful submission opens tracking', function () {
    $product = Product::factory()->withRecipe(5)->create();
    Livewire::test('pages::customer.order')->call('increment', $product->id)
        ->set('tableNumber', '4')->set('customerName', 'Customer')->set('customerPhone', '081234')
        ->set('showCart', true)->set('showCart', false)->call('refreshOrders')
        ->assertSet('quantities', [$product->id => 1])->assertSet('customerName', 'Customer')
        ->set('showCart', true)->call('submit')->assertHasNoErrors()
        ->assertSet('showCart', false)->assertSet('showTracking', true)->assertSet('quantities', [])
        ->assertSet('customerPhone', '081234')->assertSet('tableNumber', '4')
        ->assertDispatched('customer-order-submitted')
        ->assertSee('Pesanan terbaru Anda');
});

test('checkout validates name phone and table while retaining the draft', function () {
    $product = Product::factory()->withRecipe(5)->create();
    Livewire::test('pages::customer.order')->set('quantities', [$product->id => 1])
        ->set('customerName', str_repeat('a', 101))->set('customerPhone', str_repeat('1', 21))->set('tableNumber', '0')
        ->call('submit')->assertHasErrors(['customer_name', 'customer_phone', 'table_number'])
        ->assertSet('quantities', [$product->id => 1])->assertDispatched('customer-checkout-invalid');
    expect(Order::count())->toBe(0);
});

function customerTrackedOrder(string $deviceId, string $status, array $attributes = []): Order
{
    return Order::create(array_merge([
        'device_id' => $deviceId,
        'order_number' => 'OTP-'.Str::upper(Str::random(12)),
        'customer_name' => 'Customer',
        'order_type' => 'dine_in',
        'table_number' => 10,
        'status' => $status,
        'total_amount' => 15000,
        'payment_method' => 'cash',
    ], $attributes));
}

test('tracking lists every active order but limits terminal history to ten and isolates devices', function () {
    $deviceId = (string) Str::uuid();
    foreach (range(1, 12) as $index) {
        customerTrackedOrder($deviceId, 'awaiting_verification');
    }
    foreach (range(1, 12) as $index) {
        customerTrackedOrder($deviceId, 'completed');
    }
    $other = customerTrackedOrder((string) Str::uuid(), 'preparing');

    Livewire::withCookie('pos_device', $deviceId)->test('pages::customer.order')
        ->assertViewHas('activeOrders', fn ($rows) => $rows->count() === 12)
        ->assertViewHas('historyOrders', fn ($rows) => $rows->count() === 10)
        ->assertSee('Pesanan aktif: 12')->assertDontSee($other->order_number)
        ->assertSee('data-customer-subscriptions', false)->assertDontSee('wire:poll', false);
});

test('tracking represents every order status with accurate next actions', function (string $status, string $text) {
    $deviceId = (string) Str::uuid();
    $order = customerTrackedOrder($deviceId, $status);
    $page = Livewire::withCookie('pos_device', $deviceId)->test('pages::customer.order')
        ->assertSee($order->order_number)->assertSee(__('orders.status.'.$status))->assertSee($text);

    if (in_array($status, ['awaiting_inventory', 'awaiting_verification'], true)) {
        $page->assertSee('Batalkan pesanan');
    } else {
        $page->assertDontSee('Batalkan pesanan');
    }
})->with([
    ['awaiting_inventory', 'Ketersediaan bahan sedang diperiksa.'],
    ['awaiting_verification', 'Pramusaji akan memeriksa'],
    ['verifying', 'Pemakaian stok sedang diproses'],
    ['pending', 'Pesanan telah masuk antrean dapur.'],
    ['preparing', 'Menu Anda sedang disiapkan.'],
    ['ready', 'Menu siap dan akan disajikan'],
    ['awaiting_payment', 'Pesanan sudah disajikan. Bayar tunai'],
    ['waiting_payment', 'Pesanan lama ini menunggu pembayaran tunai di muka'],
    ['completed', 'Pembayaran telah dicatat.'],
    ['rejected', 'Pesanan ditolak.'],
    ['cancelled', 'Pesanan dibatalkan.'],
    ['expired', 'Pesanan kedaluwarsa.'],
]);

test('late cancellation failures stay inside tracking and realtime refresh preserves the draft', function () {
    $deviceId = (string) Str::uuid();
    $order = customerTrackedOrder($deviceId, 'awaiting_verification');
    $product = Product::factory()->withRecipe(5)->create();
    $page = Livewire::withCookie('pos_device', $deviceId)->test('pages::customer.order')
        ->set('showTracking', true)->set('showCart', true)->set('search', 'Kopi')
        ->set('categoryId', (string) $product->category_id)->set('quantities', [$product->id => 1]);
    $order->update(['status' => 'pending']);
    $page->call('cancel', $order->id)->assertHasErrors('order')->assertSee('Pesanan ini tidak dapat dibatalkan lagi.')
        ->dispatch('customer-order-view-changed')->assertSet('showTracking', true)->assertSet('showCart', true)
        ->assertSet('search', 'Kopi')->assertSet('categoryId', (string) $product->category_id)
        ->assertSet('quantities', [$product->id => 1])->assertSee('Dalam antrean dapur');
    expect($order->fresh()->status)->toBe('pending');
});
