<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('guests are redirected from the dashboard to login', function () {
    $this->get(route('dashboard.revenue'))->assertRedirect(route('login'));
});

test('manager can open the sales dashboard', function () {
    Role::findOrCreate('manager', 'web');
    $user = User::factory()->create();
    $user->assignRole('manager');

    $this->actingAs($user)->get(route('dashboard.revenue'))->assertOk();
});

test('other staff and users without a role cannot open the manager dashboard', function (?string $role) {
    $user = User::factory()->create();

    if ($role !== null) {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    }

    $this->actingAs($user)->get(route('dashboard.revenue'))->assertForbidden();
})->with(['cashier', 'kitchen', 'waiter', null]);
