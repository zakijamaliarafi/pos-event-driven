<?php

use App\Domain\Orders\SubmitOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
});

test('only staff roles can subscribe to staff order updates', function () {
    Role::findOrCreate('cashier', 'web');
    $staff = User::factory()->create();
    $staff->assignRole('cashier');
    $other = User::factory()->create();
    $request = ['socket_id' => '123.456', 'channel_name' => 'private-staff.orders'];

    $this->post('/broadcasting/auth', $request)->assertForbidden();
    $this->actingAs($other)->post('/broadcasting/auth', $request)->assertForbidden();
    $this->actingAs($staff)->post('/broadcasting/auth', $request)->assertOk();
});

test('customer private channels are limited to orders from their device cookie', function () {
    $product = Product::factory()->withStock(2)->create();
    $ownDeviceId = (string) Str::uuid();
    $otherDeviceId = (string) Str::uuid();
    $ownOrder = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ], deviceId: $ownDeviceId);
    $otherOrder = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Other customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ], deviceId: $otherDeviceId);

    $request = fn (Order $order): array => [
        'socket_id' => '123.456',
        'channel_name' => 'private-customer.order.'.$order->id,
    ];

    $this->withCookie('pos_device', $ownDeviceId)
        ->post('/broadcasting/auth', $request($ownOrder))->assertOk();
    $this->withCookie('pos_device', $ownDeviceId)
        ->post('/broadcasting/auth', $request($otherOrder))->assertForbidden();
    Auth::forgetGuards();
    $this->withCookie('pos_device', $otherDeviceId)
        ->post('/broadcasting/auth', $request($ownOrder))->assertForbidden();
});
