<?php

use App\Domain\Events\RecordDomainEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function kitchenStaff(): User
{
    Role::findOrCreate('kitchen', 'web');
    $user = User::factory()->create();
    $user->assignRole('kitchen');

    return $user;
}

function kitchenOrder(string $status, ?string $createdAt = null, ?string $projectionStatus = null): Order
{
    $order = Order::create([
        'order_number' => 'KITCHEN-'.fake()->unique()->numerify('############'),
        'status' => $status,
        'order_type' => 'dine_in',
        'table_number' => 3,
        'customer_name' => 'Pelanggan dapur',
        'total_amount' => 25000,
    ]);
    $order->forceFill(['created_at' => $createdAt ?? now()])->save();
    $product = Product::factory()->create();
    $order->items()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 12500,
        'subtotal' => 25000,
        'notes' => 'Tanpa sambal',
    ]);
    DB::table('order_read_models')->insert([
        'order_id' => $order->id,
        'order_number' => $order->order_number,
        'status' => $projectionStatus ?? $status,
        'total_amount' => $order->total_amount,
        'version' => 0,
        'created_at' => $order->created_at,
        'updated_at' => now(),
    ]);

    return $order;
}

function kitchenServiceEvent(Order $order, string $name, string $at): void
{
    DB::transaction(fn () => app(RecordDomainEvent::class)->record($order, $name, ['order_id' => $order->id]));
    DB::table('domain_outbox')->where('aggregate_id', $order->id)->where('aggregate_version', $order->version)->update(['occurred_at' => $at]);
}

test('kitchen board shows four empty lanes and refresh without polling', function () {
    $page = Livewire::actingAs(kitchenStaff())->test('pages::kitchen.view')
        ->assertSee('Antrean dapur')
        ->assertSee('0 pesanan aktif')
        ->assertSee('Tidak ada pesanan yang menunggu penyiapan.')
        ->assertSee('Tidak ada pesanan yang sedang disiapkan.')
        ->assertSee('Belum ada pesanan yang siap disajikan.')
        ->assertSee('Belum ada riwayat pesanan yang disajikan.')
        ->assertDontSee('wire:poll', false)
        ->assertDontSee('Tandai sudah disajikan');
    $this->travel(2)->seconds();
    $page->call('refreshOrders')->assertSet('refreshedAt', now()->timezone(config('app.timezone'))->format('H:i:s'));
    $this->travel(2)->seconds();
    $page->dispatch('order-view-changed')->assertSet('refreshedAt', now()->timezone(config('app.timezone'))->format('H:i:s'));
});

test('kitchen groups current statuses with projection eligibility and deterministic oldest first sorting', function () {
    $first = kitchenOrder('pending', now()->subMinutes(20)->toDateTimeString());
    $second = kitchenOrder('pending', $first->created_at->toDateTimeString());
    $preparing = kitchenOrder('preparing', projectionStatus: 'pending');
    $ready = kitchenOrder('ready');
    $leftKitchen = kitchenOrder('awaiting_payment', projectionStatus: 'ready');
    $notProjected = kitchenOrder('pending', projectionStatus: 'verifying');
    kitchenOrder('awaiting_verification');

    Livewire::actingAs(kitchenStaff())->test('pages::kitchen.view')
        ->assertViewHas('activeCount', 4)
        ->assertViewHas('lanes', function (array $lanes) use ($first, $second, $preparing, $ready): bool {
            expect($lanes['pending']['orders']->modelKeys())->toBe([$first->id, $second->id])
                ->and($lanes['preparing']['orders']->modelKeys())->toBe([$preparing->id])
                ->and($lanes['ready']['orders']->modelKeys())->toBe([$ready->id]);

            return true;
        })
        ->assertDontSee($leftKitchen->order_number)
        ->assertDontSee($notProjected->order_number)
        ->assertSee('Menunggu pramusaji');
});

test('kitchen cards retain deleted product names and escaped notes and support takeaway', function () {
    $order = kitchenOrder('pending', now()->subMinutes(17)->toDateTimeString());
    $order->update(['order_type' => 'takeaway', 'table_number' => null]);
    $product = $order->items->first()->product;
    $name = $product->name;
    $product->delete();
    $order->items->first()->update(['notes' => '<script>alert(1)</script>']);

    Livewire::actingAs(kitchenStaff())->test('pages::kitchen.view')
        ->assertSee('Bawa pulang')->assertSee($name)->assertSee('17 menit lalu')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

test('served history uses first service event and ignores later payment and duplicate events', function () {
    $older = kitchenOrder('completed', now()->subDays(2)->toDateTimeString());
    kitchenServiceEvent($older, 'OrderServed', now()->subHours(2)->toDateTimeString());
    kitchenServiceEvent($older, 'OrderCompleted', now()->toDateTimeString());
    $newer = kitchenOrder('completed');
    kitchenServiceEvent($newer, 'OrderCompleted', now()->subHour()->toDateTimeString());
    $unpaid = kitchenOrder('awaiting_payment');
    kitchenServiceEvent($unpaid, 'OrderServed', now()->subMinutes(30)->toDateTimeString());
    $legacy = kitchenOrder('completed');
    kitchenServiceEvent($older, 'CashPaymentRecorded', now()->toDateTimeString());

    Livewire::actingAs(kitchenStaff())->test('pages::kitchen.view')
        ->assertViewHas('activeCount', 0)
        ->assertViewHas('lanes', function (array $lanes) use ($older, $newer, $unpaid, $legacy): bool {
            expect($lanes['served']['orders']->modelKeys())->toBe([$unpaid->id, $newer->id, $older->id, $legacy->id]);

            return true;
        })
        ->assertSee('Belum dibayar · menunggu kasir')
        ->assertSee('Pembayaran selesai')
        ->assertSee('Waktu penyajian tidak tersedia')
        ->assertDontSee('data-kitchen-action', false);
});

test('served history limits distinct orders to thirty and sorts legacy entries by creation', function () {
    $orders = collect();
    for ($index = 0; $index < 32; $index++) {
        $order = kitchenOrder('completed', now()->subMinutes(40 - $index)->toDateTimeString());
        kitchenServiceEvent($order, 'OrderCompleted', now()->subMinutes(40 - $index)->toDateTimeString());
        $orders->push($order);
    }
    Livewire::actingAs(kitchenStaff())->test('pages::kitchen.view')
        ->assertViewHas('lanes', function (array $lanes) use ($orders): bool {
            expect($lanes['served']['orders']->modelKeys())->toBe($orders->reverse()->take(30)->pluck('id')->all());

            return true;
        });
    DB::table('domain_outbox')->delete();
    Livewire::test('pages::kitchen.view')->assertViewHas('lanes', fn (array $lanes): bool => $lanes['served']['orders']->modelKeys() === $orders->reverse()->take(30)->pluck('id')->all());
});

test('kitchen transitions move cards without waiting for projection and reject stale clicks', function () {
    $order = kitchenOrder('pending');
    $page = Livewire::actingAs(kitchenStaff())->test('pages::kitchen.view')
        ->call('start', $order->id)
        ->assertSee('Penyiapan pesanan dimulai.')
        ->assertViewHas('lanes', fn (array $lanes): bool => $lanes['preparing']['orders']->modelKeys() === [$order->id])
        ->call('start', $order->id)
        ->assertSet('orderErrors.'.$order->id, __('orders.errors.invalid_transition', ['from' => __('orders.status.pending'), 'to' => __('orders.status.preparing')]))
        ->call('ready', $order->id)
        ->assertSet('orderErrors', [])
        ->assertSee('Pesanan ditandai siap disajikan.')
        ->assertViewHas('lanes', fn (array $lanes): bool => $lanes['ready']['orders']->modelKeys() === [$order->id])
        ->call('ready', $order->id)
        ->assertSet('orderErrors.'.$order->id, __('orders.errors.preparation_pending'));
    expect($order->fresh()->status)->toBe('ready')
        ->and(DB::table('domain_outbox')->where('aggregate_id', $order->id)->count())->toBe(2);
    $page->call('start', 999999)->assertSee('Pesanan tidak ditemukan. Muat ulang halaman melalui browser.');
});

test('kitchen refresh includes newly projected orders', function () {
    $page = Livewire::actingAs(kitchenStaff())->test('pages::kitchen.view');
    $order = kitchenOrder('pending');
    $page->dispatch('order-view-changed')->assertSee($order->order_number)->assertViewHas('activeCount', 1);
    $order->update(['status' => 'preparing']);
    $page->call('refreshOrders')->assertViewHas('lanes', fn (array $lanes): bool => $lanes['preparing']['orders']->modelKeys() === [$order->id]);
});

test('kitchen routes reject guests and other roles', function () {
    $this->get(route('kitchen.view'))->assertRedirect(route('login'));
    Role::findOrCreate('manager', 'web');
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $this->actingAs($manager)->get(route('kitchen.view'))->assertForbidden();
});

test('kitchen actions reauthorize after a role is removed', function (string $action) {
    $user = kitchenStaff();
    $order = kitchenOrder('pending');
    $page = Livewire::actingAs($user)->test('pages::kitchen.view');
    $user->removeRole('kitchen');
    $page->call($action, ...($action === 'refreshOrders' ? [] : [$order->id]))->assertForbidden();
    expect($order->fresh()->status)->toBe('pending');
})->with(['start', 'ready', 'refreshOrders']);

test('kitchen feedback cannot be changed by the client', function (string $property, mixed $value) {
    Livewire::actingAs(kitchenStaff())->test('pages::kitchen.view')->set($property, $value);
})->with([
    ['message', 'Fake success'],
    ['orderErrors', [1 => 'Fake error']],
    ['refreshedAt', '00:00:00'],
])->throws(CannotUpdateLockedPropertyException::class);
