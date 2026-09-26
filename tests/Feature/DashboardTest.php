<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('guests are redirected from the dashboard to login', function () {
    $this->get(route('dashboard.revenue'))->assertRedirect(route('login'));
});

test('owner can open the sales dashboard', function () {
    Role::findOrCreate('owner', 'web');
    $user = User::factory()->create();
    $user->assignRole('owner');

    $this->actingAs($user)->get(route('dashboard.revenue'))->assertOk();
});

test('cashier cannot open the owner and manager dashboard', function () {
    Role::findOrCreate('cashier', 'web');
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $this->actingAs($user)->get(route('dashboard.revenue'))->assertForbidden();
});
