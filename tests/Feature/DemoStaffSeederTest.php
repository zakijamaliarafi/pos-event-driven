<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('demo staff seeding creates a verified account for each supported role', function () {
    $this->seed(DemoStaffSeeder::class);

    expect(User::count())->toBe(4)
        ->and(Role::count())->toBe(4);

    foreach (['cashier', 'kitchen', 'waiter', 'manager'] as $role) {
        $user = User::where('email', $role.'@example.com')->firstOrFail();

        expect($user->name)->toBe(ucfirst($role).' Demo')
            ->and($user->getRoleNames()->all())->toBe([$role])
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(Hash::check('DemoStaff123!', $user->password))->toBeTrue();
    }
});

test('demo staff seeding is repeatable and preserves existing accounts', function () {
    $existing = User::factory()->create([
        'email' => 'manager@example.com',
        'name' => 'Existing Manager',
        'password' => 'existing-password-123',
    ]);
    $originalPassword = $existing->password;

    $this->seed(DemoStaffSeeder::class);
    $passwords = User::orderBy('email')->pluck('password', 'email')->all();
    $this->seed(DemoStaffSeeder::class);

    expect(User::count())->toBe(4)
        ->and(User::orderBy('email')->pluck('password', 'email')->all())->toBe($passwords)
        ->and($existing->fresh()->name)->toBe('Existing Manager')
        ->and($existing->fresh()->password)->toBe($originalPassword)
        ->and($existing->fresh()->getRoleNames()->all())->toBe([]);
});

test('default database seeding does not create demo staff', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0);
});
