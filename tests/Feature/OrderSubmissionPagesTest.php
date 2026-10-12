<?php

use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Orders\SubmitOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function submissionPage(string $actor, Product $product): mixed
{
    if ($actor === 'cashier') {
        Role::findOrCreate('cashier', 'web');
        $user = User::factory()->create();
        $user->assignRole('cashier');
        test()->actingAs($user);
    }

    return Livewire::test($actor === 'cashier' ? 'pages::order.create' : 'pages::customer.order')
        ->set('customerName', 'Pelanggan')->set('tableNumber', '4')
        ->set('quantities', [$product->id => 1]);
}

function recordPageSubmission(mixed $page, Product $product, string $actor): Order
{
    return app(SubmitOrder::class)->handle([
        'order_type' => 'dine_in', 'table_number' => 4, 'customer_name' => 'Pelanggan',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ], $page->get('submissionKey'), cashierId: $actor === 'cashier' ? (int) auth()->id() : null,
        deviceId: $actor === 'customer' ? $page->get('deviceId') : null);
}

function postSubmissionSnapshot(array $snapshot, string $method = 'submit', array $updates = []): mixed
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => []]],
        ]],
    ]);
}

test('checkout retains keys through validation filters dismissal and realtime refresh then rotates on success', function (string $actor) {
    $product = Product::factory()->withRecipe(10)->create();
    $page = submissionPage($actor, $product);
    $key = $page->get('submissionKey');
    expect(Str::isUuid($key))->toBeTrue();
    $page->set('customerName', '')->call('submit')->assertHasErrors('customer_name')
        ->assertSet('submissionKey', $key)->assertSet('quantities', [$product->id => 1]);
    $page->set('customerName', 'Pelanggan')->set('showCart', true)->set('showCart', false)
        ->set('search', 'a')->call('resetFilters')->call('refreshOrders')->assertSet('submissionKey', $key);
    if ($actor === 'cashier') {
        $page->call('setWorkspace', 'orders')->call('setWorkspace', 'sale')->assertSet('submissionKey', $key);
    }
    $page->call('submit')->assertHasNoErrors()->assertSet('quantities', []);
    expect($page->get('submissionKey'))->not->toBe($key);
})->with(['customer', 'cashier']);

test('replaying the original signed checkout request after losing its response returns the same order', function (string $actor) {
    $product = Product::factory()->withRecipe(1)->create();
    $page = submissionPage($actor, $product);
    $snapshot = $page->snapshot;
    $first = postSubmissionSnapshot($snapshot)->assertSuccessful();
    $order = Order::firstOrFail();
    // Advance inventory before retry: both the stock and rendered cart are now stale.
    $eventId = DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->value('id');
    app(ProcessDomainEvent::class)->process($eventId);
    $product->delete();
    $replay = postSubmissionSnapshot($snapshot)->assertSuccessful();
    $state = json_decode($replay->json('components.0.snapshot'), true)['data'];
    expect($state['submittedOrderId'])->toBe($order->id)
        ->and($state['submissionKey'])->not->toBe($snapshot['data']['submissionKey'])
        ->and(Order::count())->toBe(1)
        ->and(DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->count())->toBe(1)
        ->and($first->json('components.0.effects.dispatches.0.name'))->toBe($actor === 'customer' ? 'customer-order-submitted' : 'cashier-sale-submitted');
})->with(['customer', 'cashier']);

test('edited retries preserve drafts and require an explicit new submission', function (string $actor) {
    $product = Product::factory()->withRecipe(10)->create();
    $page = submissionPage($actor, $product);
    $key = $page->get('submissionKey');
    $order = recordPageSubmission($page, $product, $actor);
    $page->set('customerName', 'Draft berubah')->set('quantities', [$product->id => 2])
        ->call('submit')->assertHasErrors('cart')->assertSet('recoveryOrderId', $order->id)
        ->assertSet('submissionKey', $key)->assertSet('quantities', [$product->id => 2])
        ->assertSee($order->order_number)->assertSee('Lihat pesanan sebelumnya')->assertSee('Mulai pesanan baru');
    $page->call('viewPreviousSubmission')->assertSet('customerName', 'Draft berubah')
        ->assertSet('quantities', [$product->id => 2])->assertSet('submissionKey', $key);
    if ($actor === 'customer') {
        $page->assertSet('showTracking', true);
    } else {
        $page->assertSet('workspace', 'orders')->assertSet('orderSearch', $order->order_number);
    }
    $page->call('startNewSubmission')->assertSet('recoveryOrderId', null)->assertHasNoErrors('cart')
        ->assertSet('customerName', 'Draft berubah')->assertSet('quantities', [$product->id => 2]);
    expect($page->get('submissionKey'))->not->toBe($key)->and(Order::count())->toBe(1);
    $page->call('submit')->assertHasNoErrors();
    expect(Order::count())->toBe(2);
})->with(['customer', 'cashier']);

test('delivery checking bypasses invalid fields and unavailable selections and never submits', function (string $actor) {
    $product = Product::factory()->withRecipe(1)->create();
    $page = submissionPage($actor, $product);
    $order = recordPageSubmission($page, $product, $actor);
    $product->delete();
    $page->set('customerName', '')->set('tableNumber', '')->set('quantities', [$product->id => 'invalid'])
        ->call('checkSubmission')->assertSet('recoveryOrderId', $order->id)->assertSee($order->order_number)
        ->assertSet('customerName', '')->assertSet('quantities', [$product->id => 'invalid'])
        ->assertSee('Periksa pengiriman')->assertViewHas('cartBlocked', true);
    expect(Order::count())->toBe(1);
})->with(['customer', 'cashier']);

test('checking an unrecorded key retains draft and does not allow reset', function (string $actor) {
    $product = Product::factory()->withRecipe()->create();
    $page = submissionPage($actor, $product);
    $key = $page->get('submissionKey');
    $page->call('checkSubmission')->assertSee('Belum ada pesanan tercatat untuk pengiriman ini')
        ->assertSet('submissionKey', $key)->assertSet('quantities', [$product->id => 1])
        ->call('startNewSubmission')->assertSet('submissionKey', $key);
    expect(Order::count())->toBe(0)->and(DB::table('order_submissions')->count())->toBe(0);
})->with(['customer', 'cashier']);

test('recovery summary remains available beyond normal customer history', function () {
    $product = Product::factory()->withRecipe(100)->create();
    $page = submissionPage('customer', $product);
    $order = recordPageSubmission($page, $product, 'customer');
    $order->update(['status' => 'cancelled']);
    for ($i = 0; $i < 11; $i++) {
        $new = app(SubmitOrder::class)->handle([
            'order_type' => 'takeaway', 'customer_name' => 'Later',
            'cart' => [['id' => $product->id, 'qty' => 1]],
        ], (string) Str::uuid(), deviceId: $page->get('deviceId'));
        $new->update(['status' => 'cancelled']);
    }
    $page->call('checkSubmission')->call('viewPreviousSubmission')->assertSee($order->order_number)
        ->assertViewHas('historyOrders', fn ($history) => $history->count() === 10 && ! $history->contains('id', $order->id))
        ->assertViewHas('recoveryOrder', fn ($recovery) => $recovery->id === $order->id);
});

test('all recovery actions enforce current submission ownership', function (string $actor, string $action) {
    $product = Product::factory()->withRecipe()->create();
    $page = submissionPage($actor, $product);
    $key = $page->get('submissionKey');
    $otherCashier = $actor === 'cashier' ? User::factory()->create()->id : null;
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'dine_in', 'table_number' => 4, 'customer_name' => 'Other',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ], $key, cashierId: $otherCashier, deviceId: $actor === 'customer' ? (string) Str::uuid() : null);
    $page->call($action)->assertHasErrors('cart')->assertSet('recoveryOrderId', null)
        ->assertSet('submissionKey', $key)->assertViewHas('recoveryOrder', null);
    if ($actor === 'customer') {
        $page->assertDontSee($order->order_number);
    }
    expect(Order::count())->toBe(1);
})->with(['customer', 'cashier'])->with(['checkSubmission', 'viewPreviousSubmission', 'startNewSubmission']);

test('cashier recovery actions reauthorize role on each request', function (string $action) {
    $page = submissionPage('cashier', Product::factory()->withRecipe()->create());
    auth()->user()->removeRole('cashier');
    $page->call($action)->assertForbidden();
})->with(['checkSubmission', 'viewPreviousSubmission', 'startNewSubmission']);

test('submission and recovery IDs cannot be changed by clients', function (string $actor, string $property) {
    $page = submissionPage($actor, Product::factory()->withRecipe()->create());
    expect(fn () => $page->set($property, $property === 'submissionKey' ? (string) Str::uuid() : 123))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['customer', 'cashier'])->with(['submissionKey', 'recoveryOrderId']);

test('predeployment snapshots without a submission key require reload', function (string $actor) {
    $page = submissionPage($actor, Product::factory()->withRecipe()->create());
    $snapshot = $page->snapshot;
    unset($snapshot['data']['submissionKey'], $snapshot['checksum']);
    $snapshot['checksum'] = Checksum::generate($snapshot);
    $response = postSubmissionSnapshot($snapshot)->assertSuccessful();
    expect($response->json('components.0.effects.html'))->toContain(__('orders.errors.submission_key_invalid'));
    expect(Order::count())->toBe(0)->and(DB::table('order_submissions')->count())->toBe(0);
})->with(['customer', 'cashier']);
