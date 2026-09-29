<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('customer ordering page is public', function () {
    $this->get(route('customer.order'))
        ->assertOk()
        ->assertSee('Oemah Tahu Purwokerto')
        ->assertSee('Buat pesanan')
        ->assertSee('Kirim pesanan')
        ->assertSee('Tampilan');
});

test('each staff role can render its POS page', function (string $role, string $route) {
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route($route))
        ->assertOk()
        ->assertSee('Oemah Tahu Purwokerto');
})->with([
    ['cashier', 'order.create'],
    ['manager', 'menu.menu'],
    ['manager', 'menu.recipes'],
    ['kitchen', 'kitchen.view'],
    ['waiter', 'waiter.view'],
    ['owner', 'dashboard.revenue'],
]);

test('cashier cannot edit the catalog', function () {
    Role::findOrCreate('cashier', 'web');
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $this->actingAs($user)->get(route('menu.menu'))->assertForbidden();
});

test('staff appearance page uses Indonesian labels and the shared brand', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('Oemah Tahu Purwokerto')
        ->assertSee('Terang')
        ->assertSee('Gelap')
        ->assertSee('Sistem');
});
