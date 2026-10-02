<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('supported staff roles can be created with prompted passwords', function (string $role) {
    $password = 'staff-test-password-123';

    $this->artisan('pos:create-staff', [
        'email' => 'staff@example.com',
        'name' => 'Staff Member',
        'role' => $role,
    ])
        ->expectsQuestion(__('staff.password'), $password)
        ->expectsQuestion(__('staff.confirm_password'), $password)
        ->assertSuccessful();

    $user = User::where('email', 'staff@example.com')->firstOrFail();

    expect(User::count())->toBe(1)
        ->and($user->name)->toBe('Staff Member')
        ->and($user->getRoleNames()->all())->toBe([$role])
        ->and(Hash::check($password, $user->password))->toBeTrue();
})->with(['cashier', 'kitchen', 'waiter', 'manager']);

test('the removed owner role cannot be provisioned', function () {
    $this->artisan('pos:create-staff', [
        'email' => 'staff@example.com',
        'name' => 'Staff Member',
        'role' => 'owner',
    ])
        ->expectsOutputToContain(__('staff.seed_roles_first'))
        ->assertFailed();

    expect(User::count())->toBe(0);
});
