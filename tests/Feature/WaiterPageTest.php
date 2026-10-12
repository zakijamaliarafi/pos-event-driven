<?php

use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('waiter', 'web');
    $this->waiter = User::factory()->create();
    $this->waiter->assignRole('waiter');
    $this->actingAs($this->waiter);
});

function waiterPageOrder(string $status, array $attributes = [], ?string $projectionStatus = null): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'WAITER-'.Str::uuid(), 'status' => $status,
        'order_type' => 'dine_in', 'table_number' => 7,
        'customer_name' => 'Pelanggan pramusaji', 'total_amount' => '25000.50',
        'device_id' => (string) Str::uuid(),
    ], $attributes));
    $product = Product::factory()->create(['name' => 'Tahu layanan']);
    $order->items()->create([
        'product_id' => $product->id, 'quantity' => 2, 'unit_price' => '12500.25',
        'subtotal' => '25000.50', 'notes' => 'Tanpa sambal',
    ]);
    DB::table('order_read_models')->insert([
        'order_id' => $order->id, 'order_number' => $order->order_number,
        'status' => $projectionStatus ?? $status, 'total_amount' => $order->total_amount,
        'version' => 0, 'created_at' => $order->created_at, 'updated_at' => $order->updated_at,
    ]);

    return $order;
}

function drainWaiterPageEvents(): void
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
    throw new RuntimeException('Waiter page events did not drain.');
}

function waiterCustomerOrder(): Order
{
    $product = Product::factory()->withRecipe(10)->create(['price' => 12500]);
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'dine_in', 'table_number' => 7, 'customer_name' => 'Pelanggan layanan',
        'cart' => [['id' => $product->id, 'qty' => 2]],
    ], idempotencyKey: (string) Str::uuid(), deviceId: (string) Str::uuid());
    drainWaiterPageEvents();

    return $order->fresh();
}

test('waiter workspace defaults to serving with accessible queues and empty states', function () {
    Livewire::test('pages::waiter.view')->assertSet('queue', 'ready')
        ->assertSee('0 pesanan aktif')->assertSee('Siap disajikan')->assertSee('Menunggu verifikasi')
        ->assertSee('Belum ada pesanan yang siap disajikan.')
        ->assertSee('Tidak ada pesanan pelanggan yang perlu diverifikasi.')
        ->assertSee('Cari pesanan')->assertSee('aria-controls="waiter-lane-ready"', false)
        ->assertSee('aria-pressed="true"', false)
        ->assertDontSee('wire:poll', false)->assertDontSee('wire:confirm', false)
        ->assertDontSee('wire:click="refresh', false);
});

test('waiter queues exclude stale projection entries and sort oldest first with ID ties', function () {
    $first = waiterPageOrder('ready');
    $second = waiterPageOrder('ready');
    $first->forceFill(['updated_at' => now()->subMinutes(10)])->save();
    $second->forceFill(['updated_at' => $first->updated_at])->save();
    $verification = waiterPageOrder('awaiting_verification');
    $later = waiterPageOrder('awaiting_verification');
    $later->forceFill(['created_at' => $verification->created_at])->save();
    $left = waiterPageOrder('awaiting_payment', projectionStatus: 'ready');
    $notProjected = waiterPageOrder('ready', projectionStatus: 'preparing');
    $cashier = waiterPageOrder('awaiting_verification', ['device_id' => null]);

    Livewire::test('pages::waiter.view')->assertViewHas('activeCount', 4)
        ->assertViewHas('lanes', function ($lanes) use ($first, $second, $verification, $later) {
            expect($lanes['ready']['orders']->modelKeys())->toBe([$first->id, $second->id])
                ->and($lanes['awaiting_verification']['orders']->modelKeys())->toBe([$verification->id, $later->id]);

            return true;
        })->assertDontSee($left->order_number)->assertDontSee($notProjected->order_number)->assertDontSee($cashier->order_number);
});

test('waiter search filters both queues while counts and selection survive realtime refresh', function () {
    $ready = waiterPageOrder('ready', ['customer_name' => 'Rani', 'table_number' => 19]);
    $verify = waiterPageOrder('awaiting_verification', ['customer_name' => 'Rani', 'table_number' => 19]);
    waiterPageOrder('ready', ['customer_name' => 'Budi', 'table_number' => 3]);
    $page = Livewire::test('pages::waiter.view')->call('selectQueue', 'awaiting_verification')
        ->set('search', ' rAnI ')->assertViewHas('activeCount', 3)
        ->assertViewHas('lanes', fn ($lanes) => $lanes['ready']['count'] === 2
            && $lanes['ready']['orders']->modelKeys() === [$ready->id]
            && $lanes['awaiting_verification']['orders']->modelKeys() === [$verify->id])
        ->assertSee('1 dari 2 pesanan');
    $this->travel(2)->seconds();
    $page->dispatch('order-view-changed')->assertSet('queue', 'awaiting_verification')->assertSet('search', ' rAnI ')
        ->assertSet('refreshedAt', now()->timezone(config('app.timezone'))->format('H:i:s'))
        ->set('search', '19')->assertViewHas('lanes', fn ($lanes) => $lanes['ready']['orders']->count() === 1)
        ->set('search', $verify->order_number)->assertSee($verify->order_number)->assertDontSee($ready->order_number)
        ->set('search', 'Tidak ditemukan')->assertSee('Pesanan tidak ditemukan dalam antrean ini.')
        ->call('clearSearch')->assertSet('search', '')->assertSet('queue', 'awaiting_verification')
        ->assertViewHas('activeCount', 3);
});

test('waiter search treats wildcard characters literally', function () {
    $matching = waiterPageOrder('ready', ['customer_name' => 'Rani_100%']);
    waiterPageOrder('ready', ['customer_name' => 'RaniX100a']);
    Livewire::test('pages::waiter.view')->set('search', '_100%')
        ->assertViewHas('lanes', fn ($lanes) => $lanes['ready']['orders']->modelKeys() === [$matching->id]);
});

test('waiter retains every active order without a silent limit', function () {
    for ($index = 0; $index < 12; $index++) {
        waiterPageOrder('ready');
    }
    Livewire::test('pages::waiter.view')->assertViewHas('activeCount', 12)
        ->assertViewHas('lanes', fn ($lanes) => $lanes['ready']['orders']->count() === 12);
});

test('waiter cards show historical amounts notes deleted names takeaway and local timestamps', function () {
    $order = waiterPageOrder('ready', ['order_type' => 'takeaway', 'table_number' => null, 'customer_name' => str_repeat('Nama panjang ', 15)]);
    $order->forceFill(['created_at' => '2026-10-05 10:00:00', 'updated_at' => '2026-10-06 11:30:00'])->save();
    $product = $order->items->first()->product;
    $product->update(['price' => 99999]);
    $product->delete();
    $order->items->first()->update(['notes' => '<script>catatan</script>']);
    Livewire::test('pages::waiter.view')->assertSee('Bawa pulang')->assertSee('Tahu layanan')->assertSee('25,000.50')
        ->assertSee($order->customer_name)->assertSee('Dipesan')->assertSee('Siap sejak')
        ->assertSee($order->updated_at->timezone(config('app.timezone'))->translatedFormat('d M Y H:i'))
        ->assertSee('&lt;script&gt;catatan&lt;/script&gt;', false)->assertDontSee('<script>catatan</script>', false)
        ->assertSee('Belum dibayar · kasir mencatat tunai setelah disajikan.');
});

test('waiter verification requires confirmation and disappears before projection delivery', function () {
    $order = waiterCustomerOrder();
    $page = Livewire::test('pages::waiter.view')->set('search', 'layanan')->call('selectQueue', 'awaiting_verification')
        ->call('openConfirmation', $order->id, 'verify')->assertSet('showConfirmation', true)
        ->assertSet('confirmationOrderId', $order->id)->assertViewHas('confirmationEligible', true)
        ->dispatch('order-view-changed')->assertSet('showConfirmation', true)
        ->call('verify', $order->id)->assertHasNoErrors()->assertSet('showConfirmation', false)
        ->assertSet('confirmationOrderId', null)->assertSet('search', 'layanan')->assertSet('queue', 'awaiting_verification')
        ->assertSet('message', $order->order_number.' — '.__('orders.messages.verified'))
        ->assertViewHas('activeCount', 0);
    expect($order->fresh()->status)->toBe('verifying')
        ->and(DB::table('order_read_models')->where('order_id', $order->id)->value('status'))->toBe('awaiting_verification');
    drainWaiterPageEvents();
    expect($order->fresh()->status)->toBe('pending');
});

test('waiter rejection uses the command and releases ingredient reservations once', function () {
    $order = waiterCustomerOrder();
    $ingredient = $order->items->first()->product->recipes->first()->inventoryItem;
    Livewire::test('pages::waiter.view')->call('openConfirmation', $order->id, 'reject')
        ->assertSee('Stok yang dicadangkan akan segera dikembalikan.')
        ->call('reject', $order->id)->assertHasNoErrors()->assertViewHas('activeCount', 0);
    expect($order->fresh()->status)->toBe('rejected');
    drainWaiterPageEvents();
    expect($ingredient->fresh()->reserved_stock)->toBe('0.00')->and($ingredient->fresh()->current_stock)->toBe('10.00');
});

test('waiter serving preserves paid and unpaid workflow outcomes', function (bool $prepaid) {
    $product = Product::factory()->withRecipe(10)->create(['price' => 12500]);
    $cashier = User::factory()->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'dine_in', 'table_number' => 4, 'customer_name' => 'Layanan',
        'cart' => [['id' => $product->id, 'qty' => 2]],
    ], idempotencyKey: (string) Str::uuid(), cashierId: $prepaid ? $cashier->id : null, deviceId: $prepaid ? null : (string) Str::uuid());
    drainWaiterPageEvents();
    if ($prepaid) {
        app(RecordCashPayment::class)->handle($order->id, $cashier->id, 'waiter-prepaid');
    } else {
        app(TransitionOrder::class)->verify($order->id);
    }
    drainWaiterPageEvents();
    app(TransitionOrder::class)->startPreparation($order->id);
    drainWaiterPageEvents();
    app(TransitionOrder::class)->markReady($order->id);
    drainWaiterPageEvents();
    $page = Livewire::test('pages::waiter.view')->call('openConfirmation', $order->id, 'serve')
        ->assertViewHas('confirmationEligible', true)
        ->assertSee($prepaid ? 'Sudah dibayar · selesai setelah disajikan.' : 'Belum dibayar · kasir mencatat tunai setelah disajikan.')
        ->call('serve', $order->id)->assertHasNoErrors()->assertSet('showConfirmation', false)->assertViewHas('activeCount', 0);
    expect($order->fresh()->status)->toBe($prepaid ? 'completed' : 'awaiting_payment');
    drainWaiterPageEvents();
    expect(DB::table('order_read_models')->where('order_id', $order->id)->value('status'))->toBe($order->fresh()->status);
    $page->call('serve', $order->id)->assertForbidden();
    expect(DB::table('domain_outbox')->where('aggregate_id', $order->id)->where('event_name', $prepaid ? 'OrderCompleted' : 'OrderServed')->count())->toBe(1);
})->with([false, true]);

test('waiter mutation cannot bypass confirmation', function (string $action) {
    $order = waiterPageOrder($action === 'serve' ? 'ready' : 'awaiting_verification');
    Livewire::test('pages::waiter.view')->call($action, $order->id)->assertForbidden();
    expect($order->fresh()->status)->toBe($action === 'serve' ? 'ready' : 'awaiting_verification');
})->with(['verify', 'reject', 'serve']);

test('waiter confirmation cannot target a different order or action', function (bool $wrongOrder) {
    $order = waiterCustomerOrder();
    $other = waiterCustomerOrder();
    Livewire::test('pages::waiter.view')->call('openConfirmation', $order->id, 'verify')
        ->call($wrongOrder ? 'verify' : 'reject', $wrongOrder ? $other->id : $order->id)->assertForbidden();
    expect($order->fresh()->status)->toBe('awaiting_verification')->and($other->fresh()->status)->toBe('awaiting_verification');
})->with([false, true]);

test('waiter cancellation race retains dialog error and disables stale confirmation', function () {
    $order = waiterCustomerOrder();
    $page = Livewire::test('pages::waiter.view')->call('openConfirmation', $order->id, 'verify');
    app(TransitionOrder::class)->cancel($order->id);
    $page->dispatch('order-view-changed')->assertSet('showConfirmation', true)->assertViewHas('confirmationEligible', false)
        ->call('verify', $order->id)->assertHasErrors('confirmation')->assertSet('confirmationFailed', true)
        ->assertSet('showConfirmation', true)->assertDispatched('waiter-confirmation-invalid')
        ->dispatch('order-view-changed')->assertHasErrors('confirmation')
        ->call('closeConfirmation')->assertSet('showConfirmation', false)->assertSet('confirmationOrderId', null)->assertHasNoErrors();
    expect($order->fresh()->status)->toBe('cancelled');
});

test('waiter handles a missing order inside confirmation', function () {
    $order = waiterPageOrder('ready');
    $page = Livewire::test('pages::waiter.view')->call('openConfirmation', $order->id, 'serve');
    DB::table('order_items')->where('order_id', $order->id)->delete();
    // Simulate a removed legacy order, which predates the retained submission ledger.
    DB::table('order_submissions')->where('order_id', $order->id)->delete();
    $order->delete();
    $page->call('serve', $order->id)->assertHasErrors('confirmation')->assertSee('Pesanan tidak ditemukan.')
        ->assertSet('showConfirmation', true)->assertViewHas('confirmationEligible', false);
});

test('waiter gracefully handles orders leaving the queue before opening confirmation', function () {
    $order = waiterPageOrder('ready');
    $order->update(['status' => 'awaiting_payment']);
    Livewire::test('pages::waiter.view')->call('openConfirmation', $order->id, 'serve')
        ->assertSet('showConfirmation', false)->assertSee('Pesanan tidak lagi tersedia dalam antrean.')
        ->assertViewHas('activeCount', 0);
});

test('waiter verification without reservations is blocked without domain writes', function () {
    $order = waiterPageOrder('awaiting_verification');
    Livewire::test('pages::waiter.view')->call('openConfirmation', $order->id, 'verify')
        ->assertViewHas('confirmationEligible', false)->call('verify', $order->id)->assertHasErrors('confirmation');
    expect($order->fresh()->status)->toBe('awaiting_verification')->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('waiter server state is locked', function (string $property, mixed $value) {
    expect(fn () => Livewire::test('pages::waiter.view')->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    ['queue', 'awaiting_verification'], ['confirmationOrderId', 99], ['confirmationAction', 'serve'],
    ['confirmationOrderNumber', 'FORGED'], ['confirmationError', 'FORGED'], ['confirmationFailed', true], ['message', 'FORGED'], ['refreshedAt', 'FORGED'],
]);

test('waiter authorizes every action after losing role access', function (string $action, array $arguments) {
    $page = Livewire::test('pages::waiter.view');
    $this->waiter->removeRole('waiter');
    $page->call($action, ...$arguments)->assertForbidden();
})->with([
    ['refreshOrders', []], ['selectQueue', ['ready']], ['clearSearch', []],
    ['openConfirmation', [1, 'serve']], ['closeConfirmation', []],
    ['verify', [1]], ['reject', [1]], ['serve', [1]],
]);

test('waiter route and component reject other staff roles', function (string $role) {
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user)->get(route('waiter.view'))->assertForbidden();
    Livewire::actingAs($user)->test('pages::waiter.view')->assertForbidden();
})->with(['cashier', 'kitchen', 'manager']);

test('waiter rejects unsupported queue and confirmation action values', function () {
    Livewire::test('pages::waiter.view')->call('selectQueue', 'completed')->assertStatus(422);
    Livewire::test('pages::waiter.view')->call('openConfirmation', 1, 'delete')->assertStatus(422);
});

test('waiter handles another waiter acting before confirmation without duplicate events', function (string $action) {
    $order = $action === 'serve' ? waiterPageOrder('ready') : waiterCustomerOrder();
    $page = Livewire::test('pages::waiter.view')->call('openConfirmation', $order->id, $action);
    if ($action === 'serve') {
        app(TransitionOrder::class)->complete($order->id);
    } else {
        app(TransitionOrder::class)->verify($order->id);
    }
    $eventCount = DB::table('domain_outbox')->where('aggregate_id', $order->id)->count();
    $page->call($action, $order->id)->assertHasErrors('confirmation')->assertSet('showConfirmation', true)
        ->assertViewHas('confirmationEligible', false)->set('search', 'layanan')->assertHasErrors('confirmation');
    expect(DB::table('domain_outbox')->where('aggregate_id', $order->id)->count())->toBe($eventCount);
})->with(['reject', 'serve']);

test('opening an unavailable order clears the previous confirmation identity', function () {
    $order = waiterPageOrder('ready');
    Livewire::test('pages::waiter.view')->call('openConfirmation', $order->id, 'serve')
        ->call('openConfirmation', 999999, 'serve')->assertSet('showConfirmation', false)
        ->assertSet('confirmationOrderId', null)->assertSet('confirmationAction', '')
        ->call('serve', $order->id)->assertForbidden();
    expect($order->fresh()->status)->toBe('ready');
});
