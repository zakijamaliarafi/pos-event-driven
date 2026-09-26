<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'cashier' => ['access pos'],
            'kitchen' => ['access kitchen display'],
            'waiter' => ['receive waiter notifications'],
            'manager' => ['manage inventory', 'view revenue dashboard'],
            'owner' => ['view revenue dashboard'],
        ];

        foreach ($permissions as $roleName => $names) {
            $role = Role::findOrCreate($roleName, 'web');

            foreach ($names as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $role->syncPermissions($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
