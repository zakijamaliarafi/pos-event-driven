<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('customer ordering page is public', function () {
    $this->get(route('customer.order'))
        ->assertOk()
        ->assertSee('Oemah Tahu Purwokerto')
        ->assertSee('Pilih menu favorit Anda')
        ->assertSee('Kirim pesanan')
        ->assertSee('data-test="theme-toggle"', false)
        ->assertDontSee('wire:poll', false)
        ->assertDontSee('Pesan sebagai pelanggan')
        ->assertDontSee('data-test="notifications-toggle"', false)
        ->assertDontSee('data-test="user-menu-toggle"', false);
});

test('each staff role can render its POS page', function (string $role, string $route) {
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route($route))
        ->assertOk()
        ->assertDontSee('wire:poll', false)
        ->assertDontSee('Oemah Tahu<br', false)
        ->assertDontSee('Pesan sebagai pelanggan')
        ->assertSee('aria-label="POS"', false)
        ->assertSee('data-test="theme-toggle"', false)
        ->assertSee('data-test="notifications-toggle"', false)
        ->assertSee('data-test="user-menu-toggle"', false)
        ->assertSee($user->name)
        ->assertSee('action="'.route('logout').'"', false)
        ->assertSee('name="_token"', false)
        ->assertSee('Keluar');
})->with([
    ['cashier', 'order.create'],
    ['manager', 'menu.menu'],
    ['manager', 'menu.recipes'],
    ['kitchen', 'kitchen.view'],
    ['waiter', 'waiter.view'],
    ['manager', 'dashboard.revenue'],
]);

test('staff are redirected to their role landing page', function (string $role, string $route) {
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('home'))->assertRedirect(route($route));
})->with([
    ['cashier', 'order.create'],
    ['manager', 'dashboard.revenue'],
    ['kitchen', 'kitchen.view'],
    ['waiter', 'waiter.view'],
]);

test('manager navigation includes the dashboard link', function () {
    Role::findOrCreate('manager', 'web');
    $user = User::factory()->create();
    $user->assignRole('manager');

    $this->actingAs($user)->get(route('menu.menu'))
        ->assertOk()
        ->assertSee('href="'.route('dashboard.revenue').'"', false);
});

test('manager pages use distinct headings and navigation only in the header', function (string $route, string $heading) {
    Role::findOrCreate('manager', 'web');
    $user = User::factory()->create();
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route($route))
        ->assertOk()
        ->assertSee($heading)
        ->assertDontSee('Pengelolaan')
        ->assertDontSee('Katalog dan stok');

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $links = $xpath->query('//header/nav/a | //header//nav/a');
    $expectedRoutes = ['dashboard.revenue', 'menu.ingredient', 'menu.recipes', 'menu.category', 'menu.menu', 'menu.discount'];

    expect(iterator_to_array($links))->toHaveCount(6);

    foreach ($expectedRoutes as $index => $expectedRoute) {
        expect($links->item($index)->getAttribute('href'))->toBe(route($expectedRoute));
    }

    $activeLinks = $xpath->query('//header//nav/a[@aria-current="page"]');
    expect($activeLinks->length)->toBe(1)
        ->and($activeLinks->item(0)->getAttribute('href'))->toBe(route($route))
        ->and($xpath->query('//main//nav[not(starts-with(@aria-label, "Halaman "))]')->length)->toBe(0)
        ->and($xpath->query('//main//h1')->length)->toBe(1)
        ->and($xpath->query('//main//h1')->item(0)->textContent)->toBe($heading);
})->with([
    ['menu.menu', 'Kelola Produk'],
    ['menu.category', 'Kelola Kategori'],
    ['menu.ingredient', 'Kelola Bahan'],
    ['menu.recipes', 'Kelola Resep'],
    ['menu.discount', 'Kelola Diskon'],
]);

test('other staff roles do not see manager navigation', function (string $role, string $route) {
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    $response = $this->actingAs($user)->get(route($route))->assertOk();

    foreach (['menu.menu', 'menu.category', 'menu.ingredient', 'menu.recipes', 'menu.discount', 'dashboard.revenue'] as $managerRoute) {
        $response->assertDontSee('href="'.route($managerRoute).'"', false);
    }
})->with([
    ['cashier', 'order.create'],
    ['kitchen', 'kitchen.view'],
    ['waiter', 'waiter.view'],
]);

test('cashier cannot edit the catalog', function () {
    Role::findOrCreate('cashier', 'web');
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $this->actingAs($user)->get(route('menu.menu'))->assertForbidden();
});

test('staff appearance page uses Indonesian labels and the compact header', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('appearance.edit'))
        ->assertOk()
        ->assertDontSee('Oemah Tahu<br', false)
        ->assertDontSee('Pesan sebagai pelanggan')
        ->assertSee('data-test="user-menu-toggle"', false)
        ->assertSee('Terang')
        ->assertSee('Gelap')
        ->assertSee('Sistem');
});

test('authenticated staff retain customer branding on the public ordering page', function () {
    Role::findOrCreate('cashier', 'web');
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $this->actingAs($user)->get(route('customer.order'))
        ->assertOk()
        ->assertSee('Oemah Tahu<br', false)
        ->assertDontSee('Pesan sebagai pelanggan')
        ->assertSee('data-test="user-menu-toggle"', false)
        ->assertSee($user->name);
});

test('table links retain customer branding after Livewire updates', function () {
    $page = $this->get(route('customer.order', ['table' => 10]))
        ->assertOk()->assertSee('Oemah Tahu<br', false)->assertSee('Meja 10');

    $document = new DOMDocument;
    @$document->loadHTML($page->getContent());
    $snapshot = (new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot');
    $response = $this->withHeaders(['X-Livewire' => ''])->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'refreshOrders', 'params' => []]],
        ]],
    ])->assertOk();
    expect($response->json('components.0.effects.html'))->toContain('Oemah Tahu<br', 'Meja 10');
});

test('header notifications show the unread count and only the latest five unread alerts', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $index) {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test-alert',
            'data' => ['title' => 'Alert '.$index, 'message' => 'Message '.$index],
            'created_at' => now()->addSeconds($index),
        ]);
    }

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test-alert',
        'data' => ['title' => 'Read alert', 'message' => 'Already read'],
        'read_at' => now(),
    ]);

    $this->actingAs($user)->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('aria-label="Notifikasi (6)"', false)
        ->assertSee('data-test="notifications-count"', false)
        ->assertSee('Alert 6')
        ->assertSee('Message 2')
        ->assertDontSee('Alert 1')
        ->assertDontSee('Read alert');
});

test('notification icons render directly in the button with and without unread alerts', function (bool $hasUnreadAlert) {
    $user = User::factory()->create();

    if ($hasUnreadAlert) {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test-alert',
            'data' => ['title' => 'Alert', 'message' => 'Message'],
        ]);
    }

    $response = $this->actingAs($user)->get(route('appearance.edit'))->assertOk();
    preg_match('/<button\b[^>]*data-test="notifications-toggle"[^>]*>(.*?)<\/button>/s', $response->getContent(), $matches);

    expect($matches[1])->toMatch('/^\s*<svg\b/');
})->with([false, true]);

test('header notifications show the empty state without an unread badge', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('aria-label="Notifikasi (0)"', false)
        ->assertSee('Belum ada notifikasi baru.')
        ->assertDontSee('data-test="notifications-count"', false)
        ->assertDontSee('Oemah Tahu<br', false)
        ->assertDontSee('Pesan sebagai pelanggan');
});

test('realtime pages refresh through broadcast events without polling', function (?string $role, string $route, string $event) {
    if ($role !== null) {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    $page = $this->get(route($route))->assertOk()->assertDontSee('wire:poll', false);
    $document = new DOMDocument;
    @$document->loadHTML($page->getContent());
    $snapshot = (new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot');

    $response = $this->withHeaders(['X-Livewire' => ''])->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '__dispatch', 'params' => [$event, []]]],
        ]],
    ])->assertOk();

    $html = $response->json('components.0.effects.html');
    expect($html)->toBeString()->not->toContain('wire:poll');

    if ($route === 'dashboard.revenue') {
        expect($html)->toMatch('/href="[^"]*\/dashboard"\s+aria-current="page"/')
            ->toContain('bg-pos-action font-semibold text-pos-action-foreground');
    }

    if ($role === null) {
        expect($html)->toContain('Oemah Tahu<br');
    }
})->with([
    ['manager', 'dashboard.revenue', 'order-view-changed'],
    ['cashier', 'order.create', 'order-view-changed'],
    ['kitchen', 'kitchen.view', 'order-view-changed'],
    ['waiter', 'waiter.view', 'order-view-changed'],
    [null, 'customer.order', 'customer-order-view-changed'],
]);

test('dashboard navigation stays active after changing the reporting period', function () {
    Role::findOrCreate('manager', 'web');
    $user = User::factory()->create();
    $user->assignRole('manager');
    $page = $this->actingAs($user)->get(route('dashboard.revenue'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($page->getContent());
    $snapshot = (new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot');

    $response = $this->withHeaders(['X-Livewire' => ''])->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['range' => 'week'],
            'calls' => [],
        ]],
    ])->assertOk();

    expect($response->json('components.0.effects.html'))
        ->toMatch('/href="[^"]*\/dashboard"\s+aria-current="page"/');
});

test('catalog navigation stays active after saving a category', function () {
    Role::findOrCreate('manager', 'web');
    $user = User::factory()->create();
    $user->assignRole('manager');
    $page = $this->actingAs($user)->get(route('menu.category'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($page->getContent());
    $snapshot = (new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot');

    $opened = $this->withHeaders(['X-Livewire' => ''])->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'createCategory', 'params' => []]],
        ]],
    ])->assertOk();
    $snapshot = $opened->json('components.0.snapshot');

    $response = $this->withHeaders(['X-Livewire' => ''])->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['categoryName' => 'New category'],
            'calls' => [['path' => '', 'method' => 'saveCategory', 'params' => []]],
        ]],
    ])->assertOk();

    expect($response->json('components.0.effects.html'))
        ->toMatch('/href="[^"]*\/menu\/categories"\s+aria-current="page"/')
        ->toContain('New category');
    $this->assertDatabaseHas('categories', ['name' => 'New category']);
});

test('POS pages have no page refresh buttons', function (?string $role, string $route) {
    if ($role !== null) {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    $response = $this->get(route($route))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//button[normalize-space(.)="Muat ulang"]')->length)->toBe(0);

    foreach ($xpath->query('//button') as $button) {
        expect($button->getAttribute('wire:click'))->not->toStartWith('refresh');
    }
})->with([
    [null, 'customer.order'],
    ['cashier', 'order.create'],
    ['kitchen', 'kitchen.view'],
    ['waiter', 'waiter.view'],
    ['manager', 'dashboard.revenue'],
    ['manager', 'menu.menu'],
    ['manager', 'menu.category'],
    ['manager', 'menu.ingredient'],
    ['manager', 'menu.recipes'],
    ['manager', 'menu.discount'],
]);
