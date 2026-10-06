<?php

use App\Domain\Catalog\ManageCatalog;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $this->actingAs($manager);
});

test('ingredient page renders stock quantities and responsive controls', function () {
    InventoryItem::factory()->create(['name' => 'Susu segar', 'unit' => 'ml', 'current_stock' => '12.55', 'reserved_stock' => '2.15']);
    InventoryItem::factory()->create(['name' => 'Bahan dihapus'])->delete();
    $response = $this->get(route('menu.ingredient'))->assertOk()
        ->assertSee('Kelola Bahan')->assertSee('Cari nama bahan...')->assertSee('Status stok')
        ->assertSee('Stok total')->assertSee('Dicadangkan')->assertSee('Stok tersedia')
        ->assertSee('12,55 ml')->assertSee('2,15 ml')->assertSee('10,40 ml')
        ->assertDontSee('wire:click="refreshIngredients"', false)->assertSee('Tambah bahan')->assertSee('Menampilkan 1 sampai 1 dari 1 bahan')
        ->assertSee('aria-label="Ubah bahan Susu segar"', false)->assertSee('aria-label="Hapus bahan Susu segar"', false)
        ->assertDontSee('Bahan dihapus')->assertDontSee('wire:poll', false);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//main//h1')->length)->toBe(1)
        ->and($xpath->query('//table/tbody/tr')->length)->toBe(1)->and($xpath->query('//article')->length)->toBe(1);
});

test('ingredient stock status uses unreserved hundredths and threshold boundaries', function (string $stock, string $reserved, string $threshold, string $available, string $status, string $filter) {
    $ingredient = InventoryItem::factory()->create(['current_stock' => $stock, 'reserved_stock' => $reserved, 'low_stock_threshold' => $threshold]);
    Livewire::test('pages::menu.manage')->set('section', 'ingredients')
        ->assertViewHas('ingredientOverview', fn ($rows) => $rows[$ingredient->id] === ['available' => $available, 'status' => $status])
        ->set('ingredientStatusFilter', $filter)->assertViewHas('ingredientRows', fn ($rows) => $rows->modelKeys() === [$ingredient->id]);
})->with([
    ['0', '0', '0', '0,00', 'Habis', 'empty'], ['2', '2', '1', '0,00', 'Habis', 'empty'],
    ['1', '2', '1', '0,00', 'Habis', 'empty'], ['0.29', '0.10', '0.19', '0,19', 'Rendah', 'low'],
    ['3', '1', '3', '2,00', 'Rendah', 'low'], ['0.30', '0.10', '0.19', '0,20', 'Cukup', 'sufficient'],
    ['0.01', '0', '0', '0,01', 'Cukup', 'sufficient'],
]);

test('ingredient search status filters pagination and refresh work together', function () {
    InventoryItem::factory()->count(11)->sequence(fn ($sequence) => ['name' => sprintf('Bahan %02d', $sequence->index)])->create();
    $empty = InventoryItem::factory()->create(['name' => 'Kosong', 'current_stock' => 2, 'reserved_stock' => 2]);
    $low = InventoryItem::factory()->create(['name' => 'Menipis', 'current_stock' => 3, 'reserved_stock' => 1, 'low_stock_threshold' => 2]);
    Livewire::test('pages::menu.manage')->set('section', 'ingredients')
        ->assertViewHas('ingredientRows', fn (LengthAwarePaginator $rows) => $rows->count() === 10 && $rows->total() === 13)
        ->call('nextPage', 'ingredientsPage')->assertSee('Menampilkan 11 sampai 13 dari 13 bahan')
        ->set('ingredientSearch', ' Bahan 03 ')->assertSet('paginators.ingredientsPage', 1)
        ->assertViewHas('ingredientRows', fn ($rows) => $rows->pluck('name')->all() === ['Bahan 03'])
        ->set('ingredientSearch', '')->call('nextPage', 'ingredientsPage')
        ->set('ingredientStatusFilter', 'empty')->assertSet('paginators.ingredientsPage', 1)
        ->assertViewHas('ingredientRows', fn ($rows) => $rows->modelKeys() === [$empty->id])
        ->set('ingredientStatusFilter', 'low')->assertViewHas('ingredientRows', fn ($rows) => $rows->modelKeys() === [$low->id])
        ->set('ingredientStatusFilter', 'sufficient')->assertViewHas('ingredientRows', fn ($rows) => $rows->total() === 11)
        ->call('nextPage', 'ingredientsPage')->call('refreshIngredients')->assertSet('paginators.ingredientsPage', 1)
        ->assertSet('ingredientStatusFilter', 'sufficient')
        ->set('ingredientSearch', 'Tidak cocok')->assertSee('Tidak ada bahan yang cocok dengan filter.')->assertSee('Reset filter')
        ->call('resetIngredientFilters')->assertSet('ingredientSearch', '')->assertSet('ingredientStatusFilter', '')
        ->assertViewHas('ingredientRows', fn ($rows) => $rows->total() === 13);
});

test('empty ingredient catalog has an actionable empty state and disabled pagination', function () {
    $response = $this->get(route('menu.ingredient'))->assertOk()->assertSee('Belum ada bahan.')
        ->assertSee('Tambah bahan pertama')->assertSee('Menampilkan 0 sampai 0 dari 0 bahan')
        ->assertDontSee('Tidak ada bahan yang cocok dengan filter.');
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    expect((new DOMXPath($document))->query('//button[@disabled and (contains(., "Sebelumnya") or contains(., "Berikutnya"))]')->length)->toBe(2);
});

test('ingredient draft confirms discard and never writes on cancellation', function () {
    $ingredient = InventoryItem::factory()->create(['name' => 'Awal']);
    Livewire::test('pages::menu.manage')->set('section', 'ingredients')
        ->call('editIngredient', $ingredient->id)->assertSet('showIngredientModal', true)
        ->assertSet('ingredientName', 'Awal')->assertSee('Stok tersimpan saat ini')
        ->set('ingredientName', 'Belum tersimpan')->call('requestIngredientClose')
        ->assertSet('showIngredientDiscardConfirmation', true)->assertDispatched('ingredient-confirmation-requested')
        ->call('saveIngredient')->assertSet('showIngredientModal', true)
        ->call('continueIngredientEditing')->assertSet('showIngredientDiscardConfirmation', false)->assertSet('ingredientName', 'Belum tersimpan')
        ->call('requestIngredientClose')->call('discardIngredientChanges')
        ->assertSet('showIngredientModal', false)->assertSet('ingredientId', null)->assertSet('ingredientName', '')
        ->assertDispatched('ingredient-dialog-closed')
        ->call('createIngredient')->assertSet('ingredientName', '')->assertSet('ingredientStock', '0')
        ->call('requestIngredientClose')->assertSet('showIngredientModal', false);
    expect($ingredient->fresh()->name)->toBe('Awal')->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('ingredient create and edit use catalog commands without changing reservations', function () {
    $page = Livewire::test('pages::menu.manage')->set('section', 'ingredients')->call('createIngredient')
        ->set('ingredientName', 'Susu')->set('ingredientUnit', 'ml')->set('ingredientStock', '10.55')->set('ingredientThreshold', '2.15')
        ->call('saveIngredient')->assertHasNoErrors()->assertSet('showIngredientModal', false)->assertSee('Bahan disimpan.');
    $ingredient = InventoryItem::sole();
    $ingredient->forceFill(['reserved_stock' => '2.00'])->save();
    $page->call('editIngredient', $ingredient->id)->set('ingredientStock', '12.25')->call('saveIngredient')
        ->assertHasNoErrors()->assertSet('ingredientId', null)->assertSet('paginators.ingredientsPage', 1);
    expect($ingredient->fresh()->current_stock)->toBe('12.25')->and($ingredient->fresh()->reserved_stock)->toBe('2.00')
        ->and(DB::table('domain_outbox')->where('event_name', 'IngredientCreated')->count())->toBe(1)
        ->and(DB::table('domain_outbox')->where('event_name', 'IngredientUpdated')->count())->toBe(1);
});

test('ingredient invalid fields keep draft and display Indonesian errors', function (string $field, string $value, string $message) {
    Livewire::test('pages::menu.manage')->set('section', 'ingredients')->call('createIngredient')
        ->set('ingredientName', 'Susu')->set('ingredientUnit', 'ml')->set($field, $value)
        ->call('saveIngredient')->assertHasErrors($field)->assertSet('showIngredientModal', true)
        ->assertSet($field, $value)->assertSee(__($message))->assertDispatched('ingredient-validation-failed');
    expect(InventoryItem::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
})->with([
    ['ingredientName', '', 'catalog.errors.ingredient_name_invalid'], ['ingredientName', str_repeat('x', 256), 'catalog.errors.ingredient_name_invalid'],
    ['ingredientUnit', '', 'catalog.errors.ingredient_unit_invalid'], ['ingredientUnit', str_repeat('x', 51), 'catalog.errors.ingredient_unit_invalid'],
    ['ingredientStock', '-1', 'catalog.errors.ingredient_quantity_invalid'], ['ingredientStock', '0.001', 'catalog.errors.ingredient_quantity_invalid'],
    ['ingredientStock', '100000000', 'catalog.errors.ingredient_quantity_invalid'], ['ingredientStock', '1e2', 'catalog.errors.ingredient_quantity_invalid'],
    ['ingredientThreshold', 'bad', 'catalog.errors.ingredient_quantity_invalid'], ['ingredientThreshold', '1.234', 'catalog.errors.ingredient_quantity_invalid'],
]);

test('ingredient commands enforce decimal bounds for direct callers', function (string $field, string $value) {
    $input = ['name' => 'Bahan', 'unit' => 'g', 'current_stock' => '0', 'low_stock_threshold' => '0'];
    $input[$field] = $value;
    expect(fn () => app(ManageCatalog::class)->saveIngredient(null, $input))->toThrow(ValidationException::class)
        ->and(InventoryItem::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
})->with([
    ['current_stock', '-0.01'], ['current_stock', '0.001'], ['current_stock', '100000000.00'],
    ['low_stock_threshold', '-0.01'], ['low_stock_threshold', '0.001'], ['low_stock_threshold', '100000000.00'],
]);

test('ingredient decimal minimum and maximum are accepted', function () {
    Livewire::test('pages::menu.manage')->set('section', 'ingredients')->call('createIngredient')
        ->set('ingredientName', 'Batas')->set('ingredientUnit', 'g')->set('ingredientStock', '99999999.99')->set('ingredientThreshold', '0')
        ->call('saveIngredient')->assertHasNoErrors();
    expect(InventoryItem::sole()->current_stock)->toBe('99999999.99')->and(InventoryItem::sole()->low_stock_threshold)->toBe('0.00');
});

test('stock below current reservations retains draft without an event', function () {
    $ingredient = InventoryItem::factory()->create(['current_stock' => 10]);
    $page = Livewire::test('pages::menu.manage')->set('section', 'ingredients')->call('editIngredient', $ingredient->id);
    $ingredient->forceFill(['reserved_stock' => '3.00'])->save();
    $page->set('ingredientStock', '2.99')->call('saveIngredient')->assertHasErrors('ingredientStock')
        ->assertSet('ingredientStock', '2.99')->assertSet('showIngredientModal', true)->assertSee(__('catalog.errors.stock_below_reserved'));
    expect($ingredient->fresh()->current_stock)->toBe('10.00')->and($ingredient->fresh()->reserved_stock)->toBe('3.00')
        ->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('ingredient save failure rolls back and draft can be retried', function () {
    $ingredient = InventoryItem::factory()->create(['name' => 'Awal', 'current_stock' => 10]);
    $page = Livewire::test('pages::menu.manage')->set('section', 'ingredients')->call('editIngredient', $ingredient->id)
        ->set('ingredientName', 'Baru')->set('ingredientStock', '12.25');
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw new RuntimeException('Simulated ingredient outbox failure');
        }
    });
    $page->call('saveIngredient')->assertHasErrors('ingredientForm')->assertSee(__('catalog.errors.ingredient_save_failed'))
        ->assertSet('showIngredientModal', true)->assertSet('ingredientName', 'Baru')->assertSet('ingredientStock', '12.25');
    expect($ingredient->fresh()->name)->toBe('Awal')->and($ingredient->fresh()->current_stock)->toBe('10.00')->and(DB::table('domain_outbox')->count())->toBe(0);
    $page->call('saveIngredient')->assertHasNoErrors()->assertSet('showIngredientModal', false);
    expect($ingredient->fresh()->name)->toBe('Baru')->and(DB::table('domain_outbox')->count())->toBe(1);
});

test('deleted ingredient keeps draft with a useful error', function () {
    $ingredient = InventoryItem::factory()->create();
    $page = Livewire::test('pages::menu.manage')->set('section', 'ingredients')->call('editIngredient', $ingredient->id);
    $ingredient->delete();
    $page->call('saveIngredient')->assertHasErrors('ingredientForm')->assertSee(__('catalog.errors.ingredient_missing'))
        ->assertSet('showIngredientModal', true)->assertSet('ingredientName', $ingredient->name);
    expect(DB::table('domain_outbox')->count())->toBe(0);
});

test('ingredient deletion requires confirmation and resets the final page', function () {
    $ingredients = InventoryItem::factory()->count(11)->sequence(fn ($sequence) => ['name' => sprintf('Bahan %02d', $sequence->index)])->create();
    $last = $ingredients->last();
    $page = Livewire::test('pages::menu.manage')->set('section', 'ingredients')->call('nextPage', 'ingredientsPage')
        ->call('requestIngredientDelete', $last->id)->assertSet('showIngredientDeleteModal', true)->assertSee($last->name)
        ->call('closeIngredientDeleteModal')->assertSet('deletingIngredientId', null);
    expect($last->fresh()->trashed())->toBeFalse();
    $page->call('requestIngredientDelete', $last->id)->call('deleteIngredient')->assertHasNoErrors()
        ->assertSet('showIngredientDeleteModal', false)->assertSet('paginators.ingredientsPage', 1)->assertSee('Bahan dihapus.');
    expect($last->fresh()->trashed())->toBeTrue()->and(DB::table('domain_outbox')->where('event_name', 'IngredientDeleted')->count())->toBe(1);
});

test('ingredient deletion explains blockers and rechecks late changes', function (bool $late, string $blocker) {
    $ingredient = InventoryItem::factory()->create();
    $page = Livewire::test('pages::menu.manage')->set('section', 'ingredients');
    if ($late) {
        $page->call('requestIngredientDelete', $ingredient->id);
    }
    if ($blocker === 'recipe') {
        Product::factory()->create()->recipes()->create(['inventory_item_id' => $ingredient->id, 'quantity_required' => 1]);
    } else {
        $ingredient->forceFill(['reserved_stock' => '1.00'])->save();
    }
    if (! $late) {
        $page->call('requestIngredientDelete', $ingredient->id)->assertSee('Bahan belum dapat dihapus.')
            ->assertSee($blocker === 'recipe' ? 'Dipakai dalam 1 resep.' : 'Masih ada 1,00 g');
    }
    $page->call('deleteIngredient')->assertHasErrors('ingredientDelete')->assertSet('showIngredientDeleteModal', true)
        ->assertSee(__('catalog.errors.ingredient_has_recipes'));
    expect($ingredient->fresh()->trashed())->toBeFalse()->and(DB::table('domain_outbox')->count())->toBe(0);
})->with([[false, 'recipe'], [false, 'reservation'], [true, 'recipe'], [true, 'reservation']]);

test('ingredient ids original draft and confirmation state are locked', function (string $property) {
    $ingredient = InventoryItem::factory()->create();
    $page = Livewire::test('pages::menu.manage')->set('section', 'ingredients')->call('editIngredient', $ingredient->id);
    expect(fn () => $page->set($property, 999999))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['ingredientId', 'originalIngredientDraft', 'deletingIngredientId', 'showIngredientDiscardConfirmation']);

test('ingredient actions require a manager', function (string $method) {
    $this->actingAs(User::factory()->create());
    $this->get(route('menu.ingredient'))->assertForbidden();
    $ingredient = InventoryItem::factory()->create();
    $page = Livewire::test('pages::menu.manage');
    $parameters = in_array($method, ['editIngredient', 'requestIngredientDelete']) ? [$ingredient->id] : [];
    $page->call($method, ...$parameters)->assertForbidden();
    expect(DB::table('domain_outbox')->count())->toBe(0);
})->with(['createIngredient', 'editIngredient', 'saveIngredient', 'requestIngredientClose', 'continueIngredientEditing',
    'discardIngredientChanges', 'refreshIngredients', 'requestIngredientDelete', 'closeIngredientDeleteModal', 'deleteIngredient']);

test('ingredient writes require an open dialog', function (string $method) {
    Livewire::test('pages::menu.manage')->set('section', 'ingredients')->call($method)->assertForbidden();
    expect(DB::table('domain_outbox')->count())->toBe(0);
})->with(['saveIngredient', 'deleteIngredient']);

test('ingredient page avoids unrelated collections and recipe choices remain complete', function () {
    InventoryItem::factory()->count(12)->create();
    InventoryItem::factory()->create(['name' => 'Arsip'])->delete();
    Livewire::test('pages::menu.manage')->set('section', 'ingredients')
        ->assertViewHas('products', fn ($rows) => $rows->isEmpty())->assertViewHas('discounts', fn ($rows) => $rows->isEmpty())
        ->assertViewHas('categories', fn ($rows) => $rows->isEmpty())->assertViewHas('ingredients', fn ($rows) => $rows->isEmpty());
    Livewire::test('pages::menu.manage')->set('section', 'recipes')->assertViewHas('ingredients', fn ($rows) => $rows->count() === 12);
});

test('ingredient overview handles long names and all stock states', function () {
    InventoryItem::factory()->create(['name' => 'A Susu segar', 'unit' => 'ml', 'current_stock' => '12.55', 'reserved_stock' => '2.15']);
    InventoryItem::factory()->create(['name' => 'B Tepung beras untuk adonan tahu dengan nama bahan yang sangat panjang', 'unit' => 'g', 'current_stock' => '20.29', 'reserved_stock' => '10.10', 'low_stock_threshold' => '10.19']);
    InventoryItem::factory()->create(['name' => 'C Garam dapur', 'unit' => 'g', 'current_stock' => 0]);
    InventoryItem::factory()->create(['name' => 'D Minyak goreng', 'unit' => 'ml', 'current_stock' => '2', 'reserved_stock' => '2']);
    InventoryItem::factory()->create(['name' => 'E Gula pasir', 'unit' => 'g', 'current_stock' => '2500.25', 'low_stock_threshold' => '500']);
    InventoryItem::factory()->count(7)->sequence(fn ($sequence) => ['name' => sprintf('F Bahan tambahan %02d', $sequence->index)])->create();
    $response = $this->get(route('menu.ingredient'))->assertOk()->assertSee('Menampilkan 1 sampai 10 dari 12 bahan')
        ->assertSee('B Tepung beras untuk adonan tahu dengan nama bahan yang sangat panjang')
        ->assertSee('10,19 g')->assertSee('Habis')->assertSee('Rendah')->assertSee('Cukup');
});
