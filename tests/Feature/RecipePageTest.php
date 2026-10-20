<?php

use App\Domain\Catalog\ManageCatalog;
use App\Models\Category;
use App\Models\InventoryItem;
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
});

test('recipes overview includes missing invalid and exhausted recipes with available portions', function () {
    Product::factory()->create(['name' => 'A Tanpa Resep', 'image_url' => null]);
    $invalid = Product::factory()->withRecipe(10, 1)->create(['name' => 'B Resep Rusak']);
    $invalid->recipes->first()->inventoryItem->delete();
    Product::factory()->withRecipe(0, 1)->create(['name' => 'C Stok Habis']);
    $available = Product::factory()->withRecipe(10.55, 2.1)->create(['name' => 'D Siap Jual']);
    $available->recipes->first()->inventoryItem->forceFill(['reserved_stock' => '2.15'])->save();
    Product::factory()->create(['name' => 'Produk dihapus'])->delete();

    $response = $this->get(route('menu.recipes'))->assertOk()
        ->assertSee('Atur kebutuhan bahan untuk satu porsi produk.')
        ->assertSeeInOrder(['A Tanpa Resep', 'B Resep Rusak', 'C Stok Habis', 'D Siap Jual'])
        ->assertSee('Belum ada resep')->assertSee('Resep tidak valid')->assertSee('Resep tersedia')
        ->assertSee('Tambah resep')->assertSee('Ubah resep')->assertDontSee('wire:click="refreshRecipes"', false)
        ->assertSee('Menampilkan 1 sampai 4 dari 4 produk')->assertDontSee('Produk dihapus')
        ->assertDontSee('wire:poll', false);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    expect((new DOMXPath($document))->query('//main//h1')->length)->toBe(1);

    Livewire::test('pages::dashboard.recipes')
        ->assertViewHas('recipeOverview', fn ($rows) => $rows[$available->id]['portions'] === 4);
});

test('recipe overview search category and presence filters reset pagination', function () {
    $category = Category::factory()->create();
    Product::factory()->count(11)->sequence(fn ($sequence) => ['name' => sprintf('Produk %02d', $sequence->index)])
        ->create(['category_id' => $category->id]);
    $withRecipe = Product::factory()->withRecipe(10, 1)->create(['name' => 'Resep lengkap']);

    Livewire::test('pages::dashboard.recipes')
        ->assertViewHas('recipeProducts', fn (LengthAwarePaginator $rows) => $rows->count() === 10 && $rows->total() === 12)
        ->call('nextPage', 'recipesPage')->assertSet('paginators.recipesPage', 2)
        ->set('recipeSearch', 'Produk 03')->assertSet('paginators.recipesPage', 1)
        ->assertViewHas('recipeProducts', fn ($rows) => $rows->pluck('name')->all() === ['Produk 03'])
        ->set('recipeSearch', '')->call('nextPage', 'recipesPage')
        ->set('recipeCategoryFilter', (string) $category->id)->assertSet('paginators.recipesPage', 1)
        ->assertViewHas('recipeProducts', fn ($rows) => $rows->total() === 11)
        ->call('resetRecipeFilters')->call('nextPage', 'recipesPage')
        ->set('recipePresenceFilter', 'with')->assertSet('paginators.recipesPage', 1)
        ->assertViewHas('recipeProducts', fn ($rows) => $rows->modelKeys() === [$withRecipe->id])
        ->set('recipePresenceFilter', 'without')->assertViewHas('recipeProducts', fn ($rows) => $rows->total() === 11)
        ->set('recipeSearch', 'Tidak cocok')->assertSee('Tidak ada produk yang cocok dengan filter.')
        ->call('resetRecipeFilters')->assertViewHas('recipeProducts', fn ($rows) => $rows->total() === 12);
});

test('empty recipe overview links to product management', function () {
    $this->get(route('menu.recipes'))->assertOk()->assertSee('Belum ada produk.')
        ->assertSee('href="'.route('menu.menu').'"', false)->assertDontSee('Tidak ada produk yang cocok dengan filter.');
});

test('recipe draft editing saves the complete recipe and excludes ingredients in other rows', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $first = $product->recipes->first()->inventoryItem;
    $second = InventoryItem::factory()->create(['name' => 'Bahan kedua', 'unit' => 'ml']);
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id)
        ->assertSet('showRecipeModal', true)->assertSet('editingRecipeProductId', $product->id);
    $key = array_key_first($page->get('recipeRows'));
    $page->assertSet('recipeRows.'.$key.'.quantity_required', '2.00')->call('addRecipeRow');
    $newKey = array_key_last($page->get('recipeRows'));
    $page->set('recipeRows.'.$newKey.'.inventory_item_id', (string) $second->id)
        ->set('recipeRows.'.$newKey.'.quantity_required', '0.25')
        ->set('recipeRows.'.$key.'.quantity_required', '3.50');
    expect($product->recipes()->sole()->quantity_required)->toBe('2.00');
    $page->call('saveWholeRecipe')->assertHasNoErrors()->assertSet('showRecipeModal', false)
        ->assertSee('Resep disimpan.')->assertDispatched('recipe-editor-closed', productId: $product->id);
    expect($product->recipes()->pluck('quantity_required', 'inventory_item_id')->all())
        ->toBe([$first->id => '3.50', $second->id => '0.25'])
        ->and(DB::table('domain_outbox')->where('event_name', 'RecipeReplaced')->count())->toBe(1);
});

test('recipe close protects dirty drafts and discarding leaves saved data unchanged', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id);
    $key = array_key_first($page->get('recipeRows'));
    $page->set('recipeRows.'.$key.'.quantity_required', '4')->call('requestRecipeClose')
        ->assertSet('showRecipeModal', true)->assertSet('showRecipeDiscardConfirmation', true)
        ->call('continueRecipeEditing')->assertSet('showRecipeDiscardConfirmation', false)
        ->assertSet('recipeRows.'.$key.'.quantity_required', '4')
        ->call('requestRecipeClose')->call('discardRecipeChanges')
        ->assertSet('showRecipeModal', false)->assertSet('recipeRows', [])
        ->call('editProductRecipe', $product->id)->call('requestRecipeClose')->assertSet('showRecipeModal', false);
    expect($product->recipes()->sole()->quantity_required)->toBe('2.00')
        ->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('clearing a whole recipe requires confirmation and can be cancelled', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id);
    $key = array_key_first($page->get('recipeRows'));
    $page->call('removeRecipeRow', $key)->call('saveWholeRecipe')->assertSet('showRecipeClearConfirmation', true)
        ->assertSet('showRecipeModal', true)->assertSee('Produk tidak tersedia untuk pesanan baru')
        ->assertDispatched('recipe-confirmation-requested');
    expect($product->recipes()->count())->toBe(1);
    $page->call('continueRecipeEditing')->assertSet('showRecipeClearConfirmation', false)
        ->call('saveWholeRecipe')->call('saveWholeRecipe')->assertSet('showRecipeModal', false);
    expect($product->recipes()->count())->toBe(0)
        ->and(DB::table('domain_outbox')->where('event_name', 'RecipeReplaced')->count())->toBe(1);
});

test('invalid recipe quantities remain in the editor with field errors', function (string $quantity) {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id);
    $key = array_key_first($page->get('recipeRows'));
    $page->set('recipeRows.'.$key.'.quantity_required', $quantity)->call('saveWholeRecipe')
        ->assertHasErrors('recipeRows.'.$key.'.quantity_required')->assertSet('showRecipeModal', true)
        ->assertSet('recipeRows.'.$key.'.quantity_required', $quantity)
        ->assertSee(__('catalog.errors.recipe_quantity_invalid'));
    expect($product->recipes()->sole()->quantity_required)->toBe('2.00')
        ->and(DB::table('domain_outbox')->count())->toBe(0);
})->with(['', '0', '-1', '0.001', '1000000', 'no quantity', '1e2']);

test('duplicate missing and soft deleted ingredients are rejected in recipe drafts', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $ingredient = $product->recipes->first()->inventoryItem;
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id)->call('addRecipeRow');
    $key = array_key_last($page->get('recipeRows'));
    $page->call('saveWholeRecipe')->assertHasErrors('recipeRows.'.$key.'.inventory_item_id')
        ->set('recipeRows.'.$key.'.inventory_item_id', (string) $ingredient->id)->call('saveWholeRecipe')
        ->assertHasErrors('recipeRows.'.$key.'.inventory_item_id')
        ->set('recipeRows.'.$key.'.inventory_item_id', '999999')->call('saveWholeRecipe')
        ->assertHasErrors('recipeRows.'.$key.'.inventory_item_id')->call('removeRecipeRow', $key);
    $ingredient->delete();
    $key = array_key_first($page->get('recipeRows'));
    $page->call('saveWholeRecipe')->assertHasErrors('recipeRows.'.$key.'.inventory_item_id');
    expect(DB::table('domain_outbox')->count())->toBe(0);
});

test('recipe conflicts preserve the draft and require explicit reload', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $ingredient = $product->recipes->first()->inventoryItem;
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id);
    $key = array_key_first($page->get('recipeRows'));
    app(ManageCatalog::class)->saveRecipe($product->id, $ingredient->id, 3);
    $page->set('recipeRows.'.$key.'.quantity_required', '4')->call('saveWholeRecipe')
        ->assertHasErrors('recipeForm')->assertSet('recipeConflict', true)->assertSet('showRecipeModal', true)
        ->assertSet('recipeRows.'.$key.'.quantity_required', '4')->assertSee('Muat ulang resep')->call('requestRecipeReload')
        ->assertSet('showRecipeDiscardConfirmation', true)->assertSee('Muat ulang dan buang')->call('discardRecipeChanges')
        ->assertSet('recipeConflict', false)->assertHasNoErrors()->assertSet('recipeExpectedVersion', 1);
    expect(array_values($page->get('recipeRows'))[0]['quantity_required'])->toBe('3.00');
    $page->call('saveWholeRecipe')->assertSet('showRecipeModal', false);
    expect($product->fresh()->version)->toBe(1);
});

test('refresh recipe overview recalculates portions without overwriting drafts', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id);
    $key = array_key_first($page->get('recipeRows'));
    $product->recipes->first()->inventoryItem->forceFill(['reserved_stock' => 6])->save();
    $page->set('recipeRows.'.$key.'.quantity_required', '3')->call('refreshRecipes')
        ->assertViewHas('recipeOverview', fn ($rows) => $rows[$product->id]['portions'] === 2)
        ->assertSet('recipeRows.'.$key.'.quantity_required', '3');
});

test('recipe ingredient choices preserve the selection and exclude other selected ingredients', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $first = $product->recipes->first()->inventoryItem;
    $second = InventoryItem::factory()->create();
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id)->call('addRecipeRow');
    $firstKey = array_key_first($page->get('recipeRows'));
    $secondKey = array_key_last($page->get('recipeRows'));
    $page->set('recipeRows.'.$secondKey.'.inventory_item_id', (string) $second->id)
        ->set('recipeIngredientSearch.'.$firstKey, 'no matching name');
    $document = new DOMDocument;
    @$document->loadHTML($page->html());
    $xpath = new DOMXPath($document);
    $firstOptions = $xpath->query('//select[@*[name()="wire:model.live"]="recipeRows.'.$firstKey.'.inventory_item_id"]/option');
    expect(array_map(fn ($option) => $option->getAttribute('value'), iterator_to_array($firstOptions)))->toBe(['', (string) $first->id]);
    $secondOptions = $xpath->query('//select[@*[name()="wire:model.live"]="recipeRows.'.$secondKey.'.inventory_item_id"]/option');
    expect(array_map(fn ($option) => $option->getAttribute('value'), iterator_to_array($secondOptions)))->not->toContain((string) $first->id);
});

test('recipe editor rejects client changes to product and version', function (string $property) {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id);
    expect(fn () => $page->set($property, 999999))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['editingRecipeProductId', 'recipeExpectedVersion', 'originalRecipeRows']);

test('deleted products retain the recipe draft with a useful error', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id);
    $draft = $page->get('recipeRows');
    $product->delete();
    $page->call('saveWholeRecipe')->assertHasErrors('recipeForm')->assertSet('recipeRows', $draft)
        ->assertSet('showRecipeModal', true)->assertSee(__('catalog.errors.recipe_product_missing'));
    expect(DB::table('domain_outbox')->count())->toBe(0);
});

test('failed recipe saves retain the draft and can be retried', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $page = Livewire::test('pages::dashboard.recipes')->call('editProductRecipe', $product->id);
    $key = array_key_first($page->get('recipeRows'));
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw new RuntimeException('Recipe outbox interrupted');
        }
    });
    $page->set('recipeRows.'.$key.'.quantity_required', '4')->call('saveWholeRecipe')
        ->assertHasErrors('recipeForm')->assertSee(__('catalog.errors.recipe_save_failed'))
        ->assertSet('showRecipeModal', true)->assertSet('recipeRows.'.$key.'.quantity_required', '4');
    expect($product->fresh()->version)->toBe(0)->and($product->recipes()->sole()->quantity_required)->toBe('2.00');
    $page->call('saveWholeRecipe')->assertHasNoErrors()->assertSet('showRecipeModal', false);
    expect($product->fresh()->version)->toBe(1)->and($product->recipes()->sole()->quantity_required)->toBe('4.00');
});

test('non managers cannot open or mutate recipe editor actions', function (string $action) {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('menu.recipes'))->assertForbidden();
    Livewire::actingAs($user)->test('pages::dashboard.recipes')->call($action, ...match ($action) {
        'editProductRecipe' => [$product->id],
        'removeRecipeRow' => ['fake-key'],
        'saveRecipe' => [],
        'deleteRecipe' => [$product->id, $product->recipes->first()->inventory_item_id],
        default => [],
    })->assertForbidden();
    expect(DB::table('domain_outbox')->count())->toBe(0);
})->with(['editProductRecipe', 'addRecipeRow', 'removeRecipeRow', 'saveWholeRecipe', 'requestRecipeClose', 'requestRecipeReload', 'discardRecipeChanges', 'saveRecipe', 'deleteRecipe']);
