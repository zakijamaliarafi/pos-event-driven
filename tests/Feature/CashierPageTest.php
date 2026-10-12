<?php

use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('cashier', 'web');
    $this->cashier = User::factory()->create();
    $this->cashier->assignRole('cashier');
    $this->actingAs($this->cashier);
});

function cashierPageOrder(Product $product, array $attributes = []): Order
{
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'dine_in', 'table_number' => 4,
        'customer_name' => 'Pelanggan kasir',
        'cart' => [['id' => $product->id, 'qty' => 2]],
    ], idempotencyKey: (string) Str::uuid(), cashierId: (int) auth()->id());
    $order->forceFill($attributes)->save();

    return $order->fresh();
}

function processCashierPageEvents(): void
{
    for ($pass = 0; $pass < 20; $pass++) {
        $ids = DB::table('domain_outbox')->whereNull('processed_at')->orderBy('sequence')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        foreach ($ids as $id) {
            app(ProcessDomainEvent::class)->process($id);
        }
    }
    throw new RuntimeException('Cashier page events did not drain.');
}

test('cashier workspace renders accessible controls and distinct empty states', function () {
    Livewire::test('pages::order.create')
        ->assertSet('workspace', 'sale')
        ->assertSee('Belum ada menu tersedia')
        ->assertSee('Keranjang masih kosong')
        ->assertSee('Tidak ada pesanan aktif.')
        ->assertSee('role="tablist"', false)
        ->assertSee('aria-controls="cashier-sale-panel"', false)
        ->assertSee('data-cashier-cart', false)
        ->assertSee('Mengirim penjualan…')
        ->assertDontSee('wire:poll', false)
        ->assertDontSee('wire:confirm', false);
});

test('cashier menu combines filters and preserves drafts through tab switches and realtime refresh', function () {
    $drinks = Category::factory()->create(['name' => 'Minuman']);
    $food = Category::factory()->create(['name' => 'Makanan']);
    $coffee = Product::factory()->withRecipe(5)->create(['name' => 'Kopi susu', 'category_id' => $drinks->id, 'image_url' => null]);
    Product::factory()->withRecipe(5)->create(['name' => 'Teh', 'category_id' => $drinks->id]);
    Product::factory()->withRecipe(5)->create(['name' => 'Tahu susu', 'category_id' => $food->id]);
    Product::factory()->create(['name' => 'Tanpa resep']);
    Product::factory()->withRecipe(0)->create(['name' => 'Stok kosong']);

    Livewire::test('pages::order.create')
        ->call('increment', $coffee->id)
        ->set('customerName', 'Draf pelanggan')->set('customerPhone', '0812345')->set('tableNumber', '7')
        ->set('search', 'SUSU')->set('categoryId', (string) $drinks->id)
        ->assertViewHas('products', fn ($rows) => $rows->modelKeys() === [$coffee->id])
        ->assertDontSee('Tanpa resep')->assertDontSee('Stok kosong')
        ->assertSee('Foto belum tersedia')->assertSee('data-customer-image-fallback', false)
        ->call('setWorkspace', 'orders')->call('setWorkspace', 'sale')->set('showCart', true)
        ->dispatch('order-view-changed')
        ->assertSet('showCart', true)->assertSet('quantities', [$coffee->id => 1])
        ->assertSet('customerName', 'Draf pelanggan')->assertSet('customerPhone', '0812345')->assertSet('tableNumber', '7')
        ->assertSet('search', 'SUSU')->assertSet('categoryId', (string) $drinks->id)
        ->set('search', 'Tidak ada')->assertSee('Menu tidak ditemukan')
        ->assertViewHas('cartRows', fn ($rows) => $rows->first()['name'] === 'Kopi susu')
        ->call('resetFilters')->assertViewHas('products', fn ($rows) => $rows->count() === 3)
        ->assertSet('quantities', [$coffee->id => 1]);
});

test('cashier quantities respect stock and hundred portion limits and zero removes a selection', function () {
    $limited = Product::factory()->withRecipe(0.29, 0.01)->create();
    $plenty = Product::factory()->withRecipe(200)->create();
    Livewire::test('pages::order.create')
        ->set('quantities', [$limited->id => 29, $plenty->id => 100])
        ->call('increment', $limited->id)->call('increment', $plenty->id)
        ->assertSet('quantities', [$limited->id => 29, $plenty->id => 100])
        ->set('quantities', [$limited->id => 1])->call('decrement', $limited->id)
        ->assertSet('quantities', [])->assertViewHas('cartBlocked', true);
});

test('cashier rejects invalid quantities before casting and retains the draft', function (mixed $value) {
    $product = Product::factory()->withRecipe(200)->create();
    Livewire::test('pages::order.create')
        ->set('orderType', 'takeaway')->set('customerName', 'Pelanggan')
        ->set('quantities', [$product->id => $value])->set('showCart', true)
        ->call('submit')->assertHasErrors('cart')->assertDispatched('cashier-checkout-invalid')
        ->assertSet('quantities', [$product->id => $value])->assertSet('customerName', 'Pelanggan')
        ->assertSet('showCart', true)->assertViewHas('cartBlocked', true);
    expect(Order::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
})->with(['fraction' => '1.9', 'float' => 1.5, 'negative' => '-1', 'over limit' => '101', 'text' => 'abc', 'blank' => '', 'nested' => [[1]], 'boolean' => true]);

test('cashier rejects empty and zero-only carts', function (array $quantities) {
    Livewire::test('pages::order.create')->set('quantities', $quantities)->call('submit')
        ->assertHasErrors('cart')->assertSee('Pilih setidaknya satu produk.');
    expect(Order::count())->toBe(0);
})->with([[[]], [[42 => 0]]]);

test('cashier rejects malformed product identifiers without writing orders', function () {
    Livewire::test('pages::order.create')->set('quantities', ['no-product' => 1])->call('submit')
        ->assertHasErrors('cart')->assertViewHas('cartBlocked', true)
        ->call('remove', 'no-product')->assertViewHas('cartRows', fn ($rows) => $rows->isEmpty());
    expect(Order::count())->toBe(0);
});

test('cashier calculates integer hundredths and submits current server prices', function () {
    $product = Product::factory()->withRecipe(5)->create(['price' => '10.25']);
    $page = Livewire::test('pages::order.create')
        ->set('customerName', 'Pelanggan')->set('customerPhone', '0812345')->set('tableNumber', '7')
        ->set('quantities', [$product->id => 2])->set('orderSearch', 'Tidak cocok')->set('orderFilter', 'payable')
        ->assertViewHas('cartTotal', 2050)->assertViewHas('cartCount', 2)->assertViewHas('cartBlocked', false);
    $product->update(['price' => '12.75']);
    $page->call('submit')->assertHasNoErrors()->assertSet('quantities', [])
        ->assertSet('workspace', 'orders')->assertSet('showCart', false)
        ->assertSet('customerName', 'Pelanggan')->assertSet('tableNumber', '7')->assertSet('customerPhone', '0812345')
        ->assertSet('orderSearch', '')->assertSet('orderFilter', 'all')->assertDispatched('cashier-sale-submitted');
    $order = Order::firstOrFail();
    expect($order->total_amount)->toBe('25.50')->and($order->status)->toBe('awaiting_inventory')
        ->and($order->user_id)->toBe($this->cashier->id)->and($order->expires_at)->not->toBeNull();
    $page->assertSet('submittedOrderId', $order->id)->assertSee('Penjualan baru dikirim');
    $this->assertDatabaseHas('domain_outbox', ['event_name' => 'OrderSubmitted', 'aggregate_id' => $order->id]);
});

test('cashier validates all checkout fields with Indonesian errors and retains drafts', function () {
    $product = Product::factory()->withRecipe(5)->create();
    Livewire::test('pages::order.create')->set('quantities', [$product->id => 1])
        ->set('tableNumber', '2147483648')->set('customerPhone', str_repeat('1', 21))->call('submit')
        ->assertHasErrors(['table_number', 'customer_name', 'customer_phone'])
        ->assertSee('nama pelanggan wajib diisi.')
        ->assertDispatched('cashier-checkout-invalid')->assertSet('quantities', [$product->id => 1]);
    expect(Order::count())->toBe(0);
});

test('takeaway ignores the retained dine-in table draft', function () {
    $product = Product::factory()->withRecipe(5)->create();
    Livewire::test('pages::order.create')->set('orderType', 'takeaway')->set('tableNumber', 'not-an-integer')
        ->set('customerName', 'Bawa pulang')->set('quantities', [$product->id => 1])->call('submit')->assertHasNoErrors()
        ->assertSet('tableNumber', 'not-an-integer');
    expect(Order::firstOrFail()->table_number)->toBeNull();
});

test('stale cart selections stay visible and removable and block submission', function (string $change) {
    $product = Product::factory()->withRecipe(5)->create(['name' => 'Pilihan lama']);
    $page = Livewire::test('pages::order.create')->set('orderType', 'takeaway')->set('customerName', 'Pelanggan')
        ->set('quantities', [$product->id => 3]);
    match ($change) {
        'deleted' => $product->delete(),
        'cleared recipe' => $product->recipes()->delete(),
        'depleted' => $product->recipes->first()->inventoryItem->update(['current_stock' => 0]),
        'reduced' => $product->recipes->first()->inventoryItem->update(['current_stock' => 2]),
    };
    $page->dispatch('order-view-changed')->assertSee('Pilihan lama')->assertViewHas('cartBlocked', true)
        ->assertSet('quantities', [$product->id => 3])->call('submit')->assertHasErrors('cart')
        ->assertSet('quantities', [$product->id => 3])->call('remove', (string) $product->id)
        ->assertViewHas('cartRows', fn ($rows) => $rows->isEmpty());
    expect(Order::count())->toBe(0);
})->with(['deleted', 'cleared recipe', 'depleted', 'reduced']);

test('cart totals apply existing discounts without changing stock', function () {
    $product = Product::factory()->withRecipe(5)->create(['price' => '100.00']);
    $discount = Discount::create(['name' => 'Potongan', 'discount_type' => 'fixed_amount', 'discount_value' => '0.25', 'schedule_type' => 'interval', 'start_date' => today()->subDay(), 'end_date' => today()->addDay(), 'is_active' => true]);
    $discount->products()->attach($product->id);
    Livewire::test('pages::order.create')->set('quantities', [$product->id => 2])->assertViewHas('cartTotal', 19950);
    expect($product->recipes->first()->inventoryItem->fresh()->current_stock)->toBe('5.00');
});

test('active orders preserve date scope and support payable search and ten row pagination', function () {
    $product = Product::factory()->withRecipe(200)->create();
    $today = cashierPageOrder($product, ['status' => 'waiting_payment']);
    $oldCashier = cashierPageOrder($product, ['created_at' => now()->subDays(2), 'status' => 'waiting_payment']);
    $customer = cashierPageOrder($product, ['device_id' => (string) Str::uuid(), 'created_at' => now()->subDays(2), 'status' => 'awaiting_payment', 'customer_name' => 'Lama dilayani', 'expires_at' => null]);
    $expired = cashierPageOrder($product, ['status' => 'waiting_payment', 'expires_at' => now()->subMinute()]);
    $pending = cashierPageOrder($product, ['status' => 'pending']);
    $processing = cashierPageOrder($product, ['status' => 'waiting_payment']);
    app(RecordCashPayment::class)->handle($processing->id, $this->cashier->id, 'processing');
    for ($i = 0; $i < 8; $i++) {
        cashierPageOrder($product);
    }
    $page = Livewire::test('pages::order.create')->call('setWorkspace', 'orders')
        ->assertViewHas('activeCount', 13)->assertViewHas('payableCount', 2)
        ->assertViewHas('orderRows', fn ($rows) => $rows->count() === 10 && $rows->total() === 13)
        ->call('nextPage', 'ordersPage')->assertViewHas('orderRows', fn ($rows) => $rows->count() === 3)
        ->set('orderFilter', 'payable')->assertSet('paginators.ordersPage', 1)
        ->assertViewHas('orderRows', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === collect([$today->id, $customer->id])->sort()->values()->all())
        ->set('orderSearch', 'LAMA')->assertViewHas('orderRows', fn ($rows) => $rows->pluck('id')->all() === [$customer->id])
        ->set('orderSearch', $today->order_number)->assertViewHas('orderRows', fn ($rows) => $rows->pluck('id')->all() === [$today->id])
        ->call('resetOrderFilters')->assertSet('orderFilter', 'all')->assertSet('orderSearch', '')
        ->set('orderSearch', $oldCashier->order_number)->assertViewHas('orderRows', fn ($rows) => $rows->isEmpty());
});

test('realtime refresh clamps pagination while retaining filters and confirmation and sale draft', function () {
    $product = Product::factory()->withRecipe(200)->create();
    for ($i = 0; $i < 11; $i++) {
        cashierPageOrder($product, ['status' => 'waiting_payment']);
    }
    $order = Order::firstOrFail();
    $page = Livewire::test('pages::order.create')->set('orderSearch', 'Pelanggan')->set('orderFilter', 'payable')
        ->set('quantities', [$product->id => 1])->set('customerName', 'Draf')
        ->call('nextPage', 'ordersPage')->call('openPayment', $order->id);
    Order::where('id', '!=', $order->id)->update(['status' => 'completed']);
    $page->dispatch('order-view-changed')->assertSet('paginators.ordersPage', 1)
        ->assertSet('orderSearch', 'Pelanggan')->assertSet('orderFilter', 'payable')
        ->assertSet('showConfirmation', true)->assertSet('confirmationOrderId', $order->id)
        ->assertSet('quantities', [$product->id => 1])->assertSet('customerName', 'Draf');
});

test('payment review uses historical prices and archived product names', function () {
    $product = Product::factory()->withRecipe(5)->create(['name' => 'Nama lama', 'price' => '12.75']);
    $order = cashierPageOrder($product, ['status' => 'waiting_payment']);
    $product->update(['price' => '999.99']);
    $product->delete();
    Livewire::test('pages::order.create')->call('openPayment', $order->id)
        ->assertSet('showConfirmation', true)->assertViewHas('confirmationEligible', true)
        ->assertViewHas('confirmationOrder', fn ($row) => $row->items->first()->unit_price === '12.75' && $row->total_amount === '25.50')
        ->assertSee('Nama lama')->assertSee('Pembayaran di muka')->assertSee('Batas pembayaran')
        ->call('pay', $order->id)->assertHasNoErrors()->assertSet('showConfirmation', false)
        ->assertSee('Pembayaran sedang diproses')->assertViewHas('payableCount', 0)
        ->assertViewHas('orderRows', fn ($rows) => ! $rows->first()->can_pay && ! $rows->first()->can_cancel);
    $this->assertDatabaseHas('cash_payments', ['order_id' => $order->id, 'amount' => '25.50', 'idempotency_key' => 'cash-order-'.$order->id]);
});

test('served customer orders use post-service cash payment without a deadline', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $order = cashierPageOrder($product, ['device_id' => (string) Str::uuid(), 'status' => 'awaiting_payment', 'expires_at' => null]);
    Livewire::test('pages::order.create')->call('openPayment', $order->id)->assertViewHas('confirmationEligible', true)
        ->assertSee('Pembayaran setelah makan')->assertDontSee('Batas pembayaran')
        ->call('pay', $order->id)->assertHasNoErrors()->assertSee('Pesanan selesai.');
    expect(DB::table('cash_payments')->where('order_id', $order->id)->count())->toBe(1);
});

test('cashier actions require initialized matching confirmations', function (string $action) {
    $product = Product::factory()->withRecipe(5)->create();
    $first = cashierPageOrder($product, ['status' => 'waiting_payment']);
    $other = cashierPageOrder($product, ['status' => 'waiting_payment']);
    Livewire::test('pages::order.create')->call($action, $first->id)->assertForbidden();
    Livewire::test('pages::order.create')->call($action === 'pay' ? 'openPayment' : 'openCancellation', $first->id)
        ->call($action, $other->id)->assertForbidden();
    expect(DB::table('cash_payments')->count())->toBe(0)->and($first->fresh()->status)->toBe('waiting_payment');
})->with(['pay', 'cancel']);

test('cashier confirmation properties cannot be changed by the client', function (string $property, mixed $value) {
    expect(fn () => Livewire::test('pages::order.create')->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    ['confirmationOrderId', 999], ['confirmationAction', 'pay'], ['confirmationOrderNumber', 'FAKE'], ['submittedOrderId', 999],
]);

test('expired prepayment is blocked and command errors stay in the payment dialog', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $order = cashierPageOrder($product, ['status' => 'waiting_payment']);
    $page = Livewire::test('pages::order.create')->call('openPayment', $order->id);
    $order->update(['expires_at' => now()->subMinute()]);
    $page->call('pay', $order->id)->assertHasErrors('confirmation')->assertSet('showConfirmation', true)
        ->assertViewHas('confirmationEligible', false)->assertSee('Batas pembayaran telah lewat');
    expect(DB::table('cash_payments')->count())->toBe(0);
});

test('a changed cancellation state keeps the late error inside its dialog', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $order = cashierPageOrder($product, ['device_id' => (string) Str::uuid(), 'status' => 'awaiting_verification', 'expires_at' => null]);
    $page = Livewire::test('pages::order.create')->call('openCancellation', $order->id);
    $order->update(['status' => 'verifying']);
    $page->call('cancel', $order->id)->assertHasErrors('confirmation')->assertSet('showConfirmation', true)
        ->assertViewHas('confirmationEligible', false)->assertSee('Pesanan ini tidak dapat dibatalkan lagi.');
    expect($order->fresh()->status)->toBe('verifying');
});

test('a removed order retains useful confirmation errors', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $order = cashierPageOrder($product);
    $page = Livewire::test('pages::order.create')->call('openCancellation', $order->id);
    // Simulate a removed legacy order, which predates the retained submission ledger.
    DB::table('order_submissions')->where('order_id', $order->id)->delete();
    $order->delete();
    $page->call('cancel', $order->id)->assertHasErrors('confirmation')->assertSet('showConfirmation', true)
        ->assertSee('Pesanan tidak ditemukan.')->call('closeConfirmation')
        ->assertSet('confirmationOrderId', null)->assertSet('confirmationAction', '');
});

test('cashier cancellation emits the existing domain event', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $order = cashierPageOrder($product);
    Livewire::test('pages::order.create')->call('openCancellation', $order->id)->call('cancel', $order->id)
        ->assertHasNoErrors()->assertSet('showConfirmation', false)->assertViewHas('activeCount', 0);
    expect($order->fresh()->status)->toBe('cancelled');
    $this->assertDatabaseHas('domain_outbox', ['event_name' => 'OrderCancelled', 'aggregate_id' => $order->id]);
});

test('existing payment idempotency survives a confirmation race', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $order = cashierPageOrder($product, ['status' => 'waiting_payment']);
    $page = Livewire::test('pages::order.create')->call('openPayment', $order->id);
    app(RecordCashPayment::class)->handle($order->id, $this->cashier->id, 'cash-order-'.$order->id);
    $page->call('pay', $order->id)->assertHasNoErrors()->assertSet('showConfirmation', false);
    expect(DB::table('cash_payments')->where('order_id', $order->id)->count())->toBe(1);
});

test('a competing payment key is reported without writing another payment', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $order = cashierPageOrder($product, ['status' => 'waiting_payment']);
    $page = Livewire::test('pages::order.create')->call('openPayment', $order->id);
    app(RecordCashPayment::class)->handle($order->id, $this->cashier->id, 'another-cashier-key');
    $page->call('pay', $order->id)->assertHasErrors('confirmation')->assertSet('showConfirmation', true)
        ->assertViewHas('confirmationEligible', false)->assertSee('Pesanan ini sudah dibayar.');
    expect(DB::table('cash_payments')->count())->toBe(1);
});

test('cashier submission preserves authoritative shared ingredient reservation and prepayment timing', function () {
    $first = Product::factory()->withRecipe(3)->create();
    $second = Product::factory()->create();
    $ingredient = $first->recipes->first()->inventoryItem;
    Recipe::create(['product_id' => $second->id, 'inventory_item_id' => $ingredient->id, 'quantity_required' => 1]);
    Livewire::test('pages::order.create')->set('orderType', 'takeaway')->set('customerName', 'Pelanggan')
        ->set('quantities', [$first->id => 2, $second->id => 2])->call('submit')->assertHasNoErrors();
    processCashierPageEvents();
    expect(Order::firstOrFail()->status)->toBe('rejected')->and($ingredient->fresh()->reserved_stock)->toBe('0.00');
});

test('cashier sale becomes payable only after stock reservation and enters kitchen after cash', function () {
    $product = Product::factory()->withRecipe(5)->create();
    $page = Livewire::test('pages::order.create')->set('orderType', 'takeaway')->set('customerName', 'Pelanggan')
        ->set('quantities', [$product->id => 2])->call('submit')->assertViewHas('payableCount', 0);
    $order = Order::firstOrFail();
    processCashierPageEvents();
    $page->dispatch('order-view-changed')->assertViewHas('payableCount', 1)
        ->call('openPayment', $order->id)->call('pay', $order->id)->assertSee('Pembayaran sedang diproses');
    processCashierPageEvents();
    $page->dispatch('order-view-changed')->assertViewHas('payableCount', 0);
    expect($order->fresh()->status)->toBe('pending')->and($product->recipes->first()->inventoryItem->fresh()->reserved_stock)->toBe('2.00');
});

test('cashier actions are forbidden after role access is revoked', function (string $action, array $arguments) {
    $page = Livewire::test('pages::order.create');
    $this->cashier->removeRole('cashier');
    $page->call($action, ...$arguments)->assertForbidden();
})->with([
    ['submit', []], ['pay', [1]], ['cancel', [1]], ['openPayment', [1]], ['openCancellation', [1]],
    ['increment', [1]], ['decrement', [1]], ['remove', ['1']], ['setWorkspace', ['orders']], ['refreshOrders', []],
]);
