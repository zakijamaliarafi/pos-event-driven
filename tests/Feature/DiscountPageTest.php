<?php

use App\Domain\Catalog\ManageCatalog;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $this->actingAs($manager);
    $this->discountProduct = Product::factory()->create();
    $this->discountInput = [
        'name' => 'Promo pembukaan', 'discount_type' => 'percentage', 'discount_value' => 10,
        'schedule_type' => 'interval', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07',
        'routine_day' => null, 'start_time' => null, 'end_time' => null, 'is_active' => true,
    ];
});

test('discount page renders the reference table with the existing navbar and schedule details', function () {
    $product = Product::factory()->create(['name' => 'Kopi pagi', 'price' => 15000]);
    app(ManageCatalog::class)->saveDiscount(null, $this->discountInput, [$product->id]);
    app(ManageCatalog::class)->saveDiscount(null, array_replace($this->discountInput, [
        'name' => 'Promo Jumat', 'discount_type' => 'fixed_amount', 'discount_value' => 5000,
        'schedule_type' => 'routine', 'start_date' => null, 'end_date' => null,
        'routine_day' => 5, 'start_time' => '08:00', 'end_time' => '10:00', 'is_active' => false,
    ]), [$this->discountProduct->id]);

    $this->get(route('menu.discount'))->assertOk()
        ->assertSee('Kelola Diskon')->assertSee('Nama &amp; nilai', false)->assertSee('Jadwal')
        ->assertSee('Produk')->assertSee('Status')->assertSee('Aksi')->assertSee('Tambah diskon')
        ->assertSee('10,00% potongan')->assertSee('Rp 5.000 potongan')->assertSee('1 produk')
        ->assertSee('Rentang tanggal')->assertSee('Rutin mingguan')->assertSee('Jumat (08:00 – 10:00)')
        ->assertSee('Tidak aktif')->assertSee('aria-current="page"', false)
        ->assertSee('aria-label="Ubah diskon Promo pembukaan"', false)
        ->assertSee('aria-label="Hapus diskon Promo pembukaan"', false)
        ->assertDontSee('wire:poll', false)->assertSee('Cari diskon')->assertDontSee('wire:click="refreshDiscounts"', false);
});

test('empty discounts and nullable schedules render safely', function () {
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->assertSee('Belum ada diskon.');
    foreach (['interval', 'routine'] as $schedule) {
        Discount::create(array_replace($this->discountInput, [
            'name' => $schedule, 'schedule_type' => $schedule, 'start_date' => null, 'end_date' => null,
        ]));
    }
    $this->get(route('menu.discount'))->assertOk()->assertSee('—')->assertSee('0 produk');
});

test('discount modal clears values and errors after cancel and starts with fresh defaults', function () {
    $discount = app(ManageCatalog::class)->saveDiscount(null, array_replace($this->discountInput, [
        'is_active' => false, 'discount_type' => 'fixed_amount', 'schedule_type' => 'routine',
        'routine_day' => 5, 'start_time' => '08:00', 'end_time' => '10:00',
    ]), [$this->discountProduct->id]);
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->assertSet('showDiscountModal', false)
        ->call('editDiscount', $discount->id)->assertSet('showDiscountModal', true)
        ->set('discountName', '')->call('saveDiscount')->assertHasErrors('discountName')
        ->call('closeDiscountModal')->assertSet('showDiscountDiscardConfirmation', true)
        ->call('discardDiscountChanges')->assertSet('showDiscountModal', false)->assertHasNoErrors()
        ->assertSet('discountId', null)->assertSet('discountName', '')->assertSet('discountValue', '0')
        ->assertSet('discountType', 'percentage')->assertSet('scheduleType', 'interval')
        ->assertSet('discountActive', true)->assertSet('selectedProducts', [])
        ->assertSet('startDate', null)->assertSet('endDate', null)->assertSet('routineDay', null)
        ->assertSet('startTime', null)->assertSet('endTime', null)
        ->call('editDiscount', $discount->id)->call('closeDiscountModal')->call('createDiscount')
        ->assertSet('showDiscountModal', true)->assertSet('discountId', null)->assertSet('discountActive', true);
    expect($discount->fresh()->name)->toBe('Promo pembukaan');
});

test('discount modal switches between date and weekly fields', function () {
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('createDiscount')
        ->assertSee('Tanggal mulai')->assertDontSee('Jam mulai')
        ->set('scheduleType', 'routine')->assertSee('Pilih hari...')->assertSee('Jam mulai')->assertSee('Jam selesai')
        ->assertDontSee('Tanggal mulai')->set('scheduleType', 'interval')->assertSee('Tanggal selesai');
});

test('invalid discount submissions show field errors and keep the modal open without domain writes', function (string $field, mixed $value) {
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('createDiscount')
        ->set('discountName', 'Promo')->set('discountValue', '10')->set('startDate', '2026-10-01')
        ->set('endDate', '2026-10-07')->set('startTime', '08:00')->set('endTime', '10:00')
        ->set('scheduleType', str_contains($field, 'Time') ? 'routine' : 'interval')
        ->set($field, $value)->call('saveDiscount')->assertHasErrors($field)
        ->assertSet('showDiscountModal', true);
    expect(Discount::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
})->with([
    ['discountName', ''], ['discountName', str_repeat('a', 256)],
    ['discountType', 'unknown'], ['discountValue', '-1'], ['discountValue', 'invalid'],
    ['endDate', '2026-09-30'], ['startTime', 'invalid'],
    ['selectedProducts', ['999999']],
]);

test('discount modal creates both schedule types with product selections and durable events', function (string $schedule) {
    $product = Product::factory()->create();
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('createDiscount')
        ->set('discountName', 'Promo')->set('discountValue', '10')->set('scheduleType', $schedule)
        ->set('startDate', '2026-10-01')->set('endDate', '2026-10-07')
        ->set('routineDay', 0)->set('startTime', '08:00')->set('endTime', '10:00')
        ->set('selectedProducts', [(string) $product->id])->call('saveDiscount')->assertHasNoErrors()
        ->assertSet('showDiscountModal', false)->assertSet('selectedProducts', [])->assertSee('Diskon disimpan.');
    $discount = Discount::sole();
    expect($discount->schedule_type)->toBe($schedule)->and($discount->is_active)->toBeTrue()
        ->and($discount->products->modelKeys())->toBe([$product->id])
        ->and($discount->start_date?->format('Y-m-d'))->toBe($schedule === 'interval' ? '2026-10-01' : null)
        ->and($discount->routine_day)->toBe($schedule === 'routine' ? 0 : null)
        ->and(DB::table('domain_outbox')->where('event_name', 'DiscountCreated')->count())->toBe(1);
})->with(['interval', 'routine']);

test('editing preloads selections and normalized times and preserves inactive status', function () {
    $products = Product::factory()->count(2)->create();
    $discount = app(ManageCatalog::class)->saveDiscount(null, array_replace($this->discountInput, [
        'schedule_type' => 'routine', 'start_date' => null, 'end_date' => null,
        'routine_day' => 5, 'start_time' => '08:00', 'end_time' => '10:00', 'is_active' => false,
    ]), [$products[0]->id]);
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('editDiscount', $discount->id)
        ->assertSet('selectedProducts', [(string) $products[0]->id])->assertSet('routineDay', 5)
        ->assertSet('startTime', '08:00')->assertSet('endTime', '10:00')->assertSet('discountActive', false)
        ->set('discountName', 'Promo baru')->set('selectedProducts', [(string) $products[1]->id])
        ->call('saveDiscount')->assertHasNoErrors()->assertSet('showDiscountModal', false);
    expect($discount->fresh()->name)->toBe('Promo baru')->and($discount->fresh()->is_active)->toBeFalse()
        ->and($discount->fresh()->products->modelKeys())->toBe([$products[1]->id])
        ->and(DB::table('domain_outbox')->where('event_name', 'DiscountUpdated')->count())->toBe(1);
});

test('date edit prefill and switching schedules clear irrelevant stored fields', function () {
    $discount = app(ManageCatalog::class)->saveDiscount(null, $this->discountInput, [$this->discountProduct->id]);
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('editDiscount', $discount->id)
        ->assertSet('startDate', '2026-10-01')->assertSet('endDate', '2026-10-07')
        ->set('scheduleType', 'routine')->set('routineDay', 5)->set('startTime', '08:00')->set('endTime', '10:00')
        ->call('saveDiscount')->assertHasNoErrors();
    expect($discount->fresh()->start_date)->toBeNull()->and($discount->fresh()->end_date)->toBeNull();
});

test('status badges toggle and delete refreshes the table with durable events', function () {
    $discount = app(ManageCatalog::class)->saveDiscount(null, $this->discountInput, [$this->discountProduct->id]);
    $page = Livewire::test('pages::menu.manage')->set('section', 'discounts')
        ->call('toggleDiscount', $discount->id)->assertSee('Tidak aktif');
    expect($discount->fresh()->is_active)->toBeFalse();
    $page->call('toggleDiscount', $discount->id);
    expect($discount->fresh()->is_active)->toBeTrue();
    $page->call('deleteDiscount', $discount->id)->assertSet('showDiscountDeleteModal', true);
    expect($discount->fresh()->trashed())->toBeFalse();
    $page->call('confirmDiscountDeletion')->assertSee('Belum ada diskon.');
    expect($discount->fresh()->trashed())->toBeTrue()
        ->and(DB::table('domain_outbox')->where('event_name', 'DiscountToggled')->count())->toBe(2)
        ->and(DB::table('domain_outbox')->where('event_name', 'DiscountDeleted')->count())->toBe(1);
});

test('discount filters combine and reset named pagination while refresh preserves filters', function () {
    foreach (range(1, 12) as $number) {
        Discount::create(array_replace($this->discountInput, ['name' => sprintf('Promo %02d', $number)]));
    }
    $weekly = Discount::create(array_replace($this->discountInput, ['name' => 'Promo Minggu', 'schedule_type' => 'routine', 'is_active' => false]));
    Discount::create(array_replace($this->discountInput, ['name' => 'Dihapus']))->delete();
    Livewire::test('pages::menu.manage')->set('section', 'discounts')
        ->assertViewHas('discountRows', fn (LengthAwarePaginator $rows) => $rows->count() === 10 && $rows->total() === 13)
        ->call('nextPage', 'discountsPage')->assertSee('Menampilkan 11 sampai 13 dari 13 diskon')
        ->set('discountSearch', ' Promo ')->assertSet('paginators.discountsPage', 1)
        ->set('discountStatusFilter', 'inactive')->set('discountScheduleFilter', 'routine')
        ->assertViewHas('discountRows', fn ($rows) => $rows->modelKeys() === [$weekly->id])
        ->call('refreshDiscounts')->assertSet('discountStatusFilter', 'inactive')->assertSet('discountScheduleFilter', 'routine')
        ->set('discountScheduleFilter', 'interval')->assertSee('Tidak ada diskon yang cocok dengan filter.')
        ->call('resetDiscountFilters')->assertSet('discountSearch', '')->assertSet('discountStatusFilter', '')->assertSet('discountScheduleFilter', '')
        ->assertViewHas('discountRows', fn ($rows) => $rows->total() === 13);
});

test('bulk product controls change matching results and preserve hidden selections', function () {
    $category = Category::factory()->create();
    $matching = Product::factory()->count(2)->create(['name' => 'Kopi cocok', 'category_id' => $category->id]);
    $otherCategory = Product::factory()->create(['name' => 'Kopi lain']);
    $deleted = Product::factory()->create(['name' => 'Kopi dihapus', 'category_id' => $category->id]);
    $deleted->delete();
    $page = Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('createDiscount')
        ->set('selectedProducts', [(string) $this->discountProduct->id])->set('discountProductSearch', 'Kopi')
        ->set('discountProductCategoryFilter', (string) $category->id)->call('selectDiscountProductResults');
    expect($page->get('selectedProducts'))->toEqualCanonicalizing(array_map('strval', [$this->discountProduct->id, ...$matching->modelKeys()]));
    $page->call('clearDiscountProductResults')->assertSet('selectedProducts', [(string) $this->discountProduct->id])
        ->set('discountProductSearch', 'Tidak cocok')->assertSee('Tidak ada produk yang cocok.')
        ->call('selectDiscountProductResults')->assertSet('selectedProducts', [(string) $this->discountProduct->id]);
    expect($page->get('selectedProducts'))->not->toContain((string) $otherCategory->id, (string) $deleted->id)
        ->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('discount discard confirmation protects fields products and schedule drafts', function () {
    $page = Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('createDiscount')
        ->set('discountName', 'Draft')->set('startDate', '2026-10-01')->set('endDate', '2026-10-07')
        ->set('selectedProducts', [(string) $this->discountProduct->id])->set('discountValue', '10')
        ->set('scheduleType', 'routine')->set('scheduleType', 'interval')->assertSet('startDate', '2026-10-01')
        ->call('closeDiscountModal')->assertSet('showDiscountDiscardConfirmation', true)->assertDispatched('discount-confirmation-requested')
        ->call('saveDiscount')->assertSet('showDiscountModal', true)
        ->call('continueDiscountEditing')->assertSet('discountName', 'Draft')->assertDispatched('discount-editing-resumed')
        ->call('closeDiscountModal')->call('discardDiscountChanges')->assertSet('showDiscountModal', false)->assertDispatched('discount-dialog-closed');
    expect(Discount::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('legacy incomplete discounts can be disabled and deleted but cannot be enabled', function () {
    $discount = Discount::create(array_replace($this->discountInput, ['start_date' => null, 'end_date' => null]));
    $page = Livewire::test('pages::menu.manage')->set('section', 'discounts')->assertSee('Jadwal perlu diperbaiki.')
        ->assertSee('Pilih produk.')->call('toggleDiscount', $discount->id)->assertHasNoErrors();
    $version = $discount->fresh()->version;
    $page->call('toggleDiscount', $discount->id)->assertHasErrors('discountList')->assertSee('Gunakan Ubah');
    expect($discount->fresh()->is_active)->toBeFalse()->and($discount->fresh()->version)->toBe($version);
    $page->call('deleteDiscount', $discount->id)->call('confirmDiscountDeletion')->assertHasNoErrors();
    expect($discount->fresh()->trashed())->toBeTrue();
});

test('discount save retains a draft after late removal and outbox failure', function () {
    $discount = app(ManageCatalog::class)->saveDiscount(null, $this->discountInput, [$this->discountProduct->id]);
    $page = Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('editDiscount', $discount->id)->set('discountName', 'Draft');
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw new RuntimeException('outbox unavailable');
        }
    });
    $page->call('saveDiscount')->assertHasErrors('discountForm')->assertSet('discountName', 'Draft')->assertSet('showDiscountModal', true);
    expect($discount->fresh()->name)->toBe('Promo pembukaan')->and($discount->fresh()->version)->toBe(1);
    $page->call('saveDiscount')->assertHasNoErrors()->assertSet('showDiscountModal', false);
    $page->call('editDiscount', $discount->id)->set('discountName', 'Late draft');
    $discount->delete();
    $page->call('saveDiscount')->assertHasErrors('discountForm')->assertSet('discountName', 'Late draft')->assertSet('showDiscountModal', true);
});

test('discount deletion retains confirmation after late removal and failed outbox writes', function () {
    $discount = app(ManageCatalog::class)->saveDiscount(null, $this->discountInput, [$this->discountProduct->id]);
    $page = Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('deleteDiscount', $discount->id);
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw new RuntimeException('outbox unavailable');
        }
    });
    $page->call('confirmDiscountDeletion')->assertHasErrors('discountDelete')->assertSet('showDiscountDeleteModal', true);
    expect($discount->fresh()->trashed())->toBeFalse();
    $discount->delete();
    $page->call('confirmDiscountDeletion')->assertHasErrors('discountDelete')->assertSet('showDiscountDeleteModal', true)
        ->call('closeDiscountDeleteModal')->assertSet('showDiscountDeleteModal', false);
});

test('discount state identifiers and discard baseline are locked', function (string $property, mixed $value) {
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->set($property, $value);
})->with([
    ['discountId', 999], ['deletingDiscountId', 999], ['deletingDiscountName', 'Forged'],
    ['originalDiscountDraft', ['name' => 'Forged']], ['showDiscountDiscardConfirmation', true],
])->throws(CannotUpdateLockedPropertyException::class);

test('discount actions require manager authorization', function (string $action, array $arguments) {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->call($action, ...$arguments)->assertForbidden();
})->with([
    ['createDiscount', []], ['editDiscount', [1]], ['saveDiscount', []], ['toggleDiscount', [1]],
    ['deleteDiscount', [1]], ['confirmDiscountDeletion', []], ['selectDiscountProductResults', []],
    ['clearDiscountProductResults', []], ['closeDiscountModal', []], ['discardDiscountChanges', []],
]);

test('discount mutations require initialized forms and confirmations', function (string $action) {
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->call($action)->assertForbidden();
})->with(['saveDiscount', 'confirmDiscountDeletion', 'selectDiscountProductResults', 'clearDiscountProductResults']);

test('discount list renders long names fractional fixed amounts and accessible responsive actions', function () {
    $name = str_repeat('Promo spesial panjang ', 10);
    app(ManageCatalog::class)->saveDiscount(null, array_replace($this->discountInput, ['name' => $name, 'discount_type' => 'fixed_amount', 'discount_value' => '0.01']), [$this->discountProduct->id]);
    $response = $this->get(route('menu.discount'))->assertOk()->assertSee($name)->assertSee('Rp 0,01 potongan')
        ->assertDontSee('wire:confirm', false)->assertDontSee('wire:poll', false);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//main//h1')->length)->toBe(1)
        ->and($xpath->query('//table/tbody/tr')->length)->toBe(1)->and($xpath->query('//article')->length)->toBe(1)
        ->and($xpath->query('//button[contains(@class, "min-h-11") and contains(@aria-label, "diskon")]')->length)->toBe(6);
});

test('empty product catalog explains why the discount cannot be saved', function () {
    $this->discountProduct->delete();
    Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('createDiscount')
        ->assertSee('Belum ada produk.')->assertSee('Kelola produk')->assertSee('0 produk dipilih')
        ->set('discountName', 'Promo')->set('discountValue', '10')->set('startDate', '2026-10-01')->set('endDate', '2026-10-07')
        ->call('saveDiscount')->assertHasErrors('selectedProducts')->assertSet('showDiscountModal', true);
    expect(Discount::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('discount catalog queries exclude unrelated inventory and keep complete product choices', function () {
    Product::factory()->count(12)->create();
    $page = Livewire::test('pages::menu.manage')->set('section', 'discounts');
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $this->get(route('menu.discount'))->assertOk();
    $page->call('refreshDiscounts')
        ->assertViewHas('products', fn ($rows) => $rows->count() === 13)
        ->assertViewHas('ingredients', fn ($rows) => $rows->isEmpty());
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'from `inventory_items`') || str_contains($sql, 'from `recipes`')))->toBeFalse();
});

test('filter changes product saves deletion and status toggles reset discount pagination', function () {
    foreach (range(1, 11) as $number) {
        app(ManageCatalog::class)->saveDiscount(null, array_replace($this->discountInput, ['name' => sprintf('Promo %02d', $number)]), [$this->discountProduct->id]);
    }
    $last = Discount::orderBy('name', 'desc')->first();
    $page = Livewire::test('pages::menu.manage')->set('section', 'discounts')->call('nextPage', 'discountsPage')
        ->call('editDiscount', $last->id)->set('discountName', 'Updated')->call('saveDiscount')->assertSet('paginators.discountsPage', 1)
        ->call('nextPage', 'discountsPage')->call('toggleDiscount', $last->id)->assertSet('paginators.discountsPage', 1)
        ->call('nextPage', 'discountsPage')->call('deleteDiscount', $last->id)->call('confirmDiscountDeletion')
        ->assertSet('paginators.discountsPage', 1)->assertViewHas('discountRows', fn ($rows) => $rows->total() === 10);
});
