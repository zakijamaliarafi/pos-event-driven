<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('public staff registration is disabled', function () {
    expect(Route::has('register'))->toBeFalse();
});

test('staff roles can be seeded without fixed user passwords', function () {
    $expectedPermissions = [
        'cashier' => ['access pos'],
        'kitchen' => ['access kitchen display'],
        'manager' => ['manage inventory', 'view revenue dashboard'],
        'waiter' => ['receive waiter notifications'],
    ];

    for ($iteration = 0; $iteration < 2; $iteration++) {
        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder'])->assertSuccessful();

        expect(Role::orderBy('name')->pluck('name')->all())->toBe(array_keys($expectedPermissions))
            ->and(Permission::count())->toBe(5)
            ->and(User::count())->toBe(0);

        foreach ($expectedPermissions as $role => $permissions) {
            expect(Role::findByName($role, 'web')->permissions()->orderBy('name')->pluck('name')->all())
                ->toBe($permissions);
        }
    }
});
