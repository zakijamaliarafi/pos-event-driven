<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

final class CreateStaffUser extends Command
{
    protected $signature = 'pos:create-staff {email} {name} {role}';

    protected $description = 'Create a POS staff user with an interactively entered password';

    public function handle(): int
    {
        $role = (string) $this->argument('role');

        if (! in_array($role, ['cashier', 'kitchen', 'waiter', 'manager', 'owner'], true) || ! Role::where('name', $role)->exists()) {
            $this->components->error('Seed roles first, then choose a valid staff role.');

            return self::FAILURE;
        }

        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $this->argument('email'),
            'name' => $this->argument('name'),
            'password' => $password,
            'confirmation' => $confirmation,
        ], [
            'email' => 'required|email|unique:users,email',
            'name' => 'required|string|max:255',
            'password' => ['required', Password::min(12)],
            'confirmation' => 'same:password',
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);
        $this->components->info("Created {$role} account for {$user->email}.");

        return self::SUCCESS;
    }
}
