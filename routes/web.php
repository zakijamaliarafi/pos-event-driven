<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = Auth::user();

    if ($user->hasRole('cashier')) {
        return redirect('/order/create');
    }

    if ($user->hasRole('manager')) {
        return redirect()->route('dashboard.revenue');
    }

    if ($user->hasRole('kitchen')) {
        return redirect('/kitchen');
    }

    if ($user->hasRole('waiter')) {
        return redirect('/waiter');
    }
})->middleware('auth')->name('home');

Route::middleware(['auth', 'role:cashier'])->group(function () {
    Route::livewire('/order/create', 'pages::order.create')->name('order.create');
});

Route::middleware(['auth', 'role:manager'])->group(function () {
    Route::livewire('/ingredient', 'pages::dashboard.ingredients')->name('menu.ingredient');
    Route::livewire('/menu', 'pages::dashboard.products')->name('menu.menu');
    Route::livewire('/menu/categories', 'pages::dashboard.categories')->name('menu.category');
    Route::livewire('/menu/discounts', 'pages::dashboard.discounts')->name('menu.discount');
    Route::livewire('/menu/recipes', 'pages::dashboard.recipes')->name('menu.recipes');
});

Route::middleware(['auth', 'role:kitchen'])->group(function () {
    Route::livewire('/kitchen', 'pages::kitchen.view')->name('kitchen.view');
});

Route::middleware(['auth', 'role:waiter'])->group(function () {
    Route::livewire('/waiter', 'pages::waiter.view')->name('waiter.view');
});

Route::middleware(['auth', 'role:manager'])->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard.revenue')->name('dashboard.revenue');
});

Route::livewire('/order', 'pages::customer.order')->name('customer.order');

require __DIR__.'/settings.php';
