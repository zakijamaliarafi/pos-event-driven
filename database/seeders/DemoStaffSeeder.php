<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        foreach (['cashier', 'kitchen', 'waiter', 'manager'] as $role) {
            $user = User::firstOrNew(['email' => $role.'@gmail.com']);

            if ($user->exists) {
                continue;
            }

            $user->name = ucfirst($role).' Demo';
            $user->password = 'zaki1745';
            $user->email_verified_at = now();
            $user->save();
            $user->assignRole($role);
        }
    }
}
