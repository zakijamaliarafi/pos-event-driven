<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('public staff registration is disabled', function () {
    expect(Route::has('register'))->toBeFalse();
});

test('staff roles can be seeded without fixed user passwords', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder'])->assertSuccessful();

    expect(Role::count())->toBe(5)
        ->and(User::count())->toBe(0);
});
