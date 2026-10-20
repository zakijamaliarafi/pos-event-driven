<?php

use App\Domain\Catalog\ManageCatalog;
use App\Domain\Catalog\ProductAvailability;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\OemahTahuMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('menu import populates all manager catalog pages with zero stock and valid draft recipes', function () {
    $this->seed(OemahTahuMenuSeeder::class);

    expect(Category::count())->toBe(28)
        ->and(Product::count())->toBe(238)
        ->and(InventoryItem::count())->toBe(137)
        ->and(Recipe::count())->toBe(1412)
        ->and(InventoryItem::where('current_stock', '!=', 0)->exists())->toBeFalse()
        ->and(InventoryItem::where('reserved_stock', '!=', 0)->exists())->toBeFalse()
        ->and(Product::where('name', 'like', '%Wedding%')->exists())->toBeFalse()
        ->and(Product::where('name', 'Jamu Beras Kencur')->count())->toBe(1)
        ->and(Product::where('name', 'Oseng Pete Sambal')->count())->toBe(1);

    $availability = app(ProductAvailability::class);
    foreach (Product::with('recipes.inventoryItem')->get() as $product) {
        expect($availability->hasValidRecipe($product))->toBeTrue($product->name)
            ->and($availability->availableQuantity($product))->toBe(0);
    }

    expect(Product::where('name', 'Tahu Sutra (Oleh-oleh)')->sole()->price)->toBe('15000.00')
        ->and(Product::where('name', 'Tahu Sutra Goreng (10 Biji)')->sole()->price)->toBe('14500.00')
        ->and(Product::where('name', 'Nasi Bancakan Khas Oemah Tahu (Ayam Kampung) (15 Orang)')->sole()->price)->toBe('995000.00')
        ->and(DB::table('domain_outbox')->where('event_name', 'ProductCreated')->count())->toBe(238)
        ->and(DB::table('domain_outbox')->where('event_name', 'RecipeReplaced')->count())->toBe(238);

    Role::findOrCreate('manager', 'web');
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $this->actingAs($manager);

    $this->get(route('menu.ingredient'))->assertOk()->assertSee('Air minum');
    $this->get(route('menu.recipes'))->assertOk()->assertSee('Arem Arem Sayur');
    $this->get(route('menu.category'))->assertOk()->assertSee('Aneka Gorengan');
    $this->get(route('menu.menu'))->assertOk()->assertSee('Arem Arem Sayur');
});

test('repeating menu import preserves edited prices recipes and ingredient stock without new events', function () {
    $this->seed(OemahTahuMenuSeeder::class);
    $catalog = app(ManageCatalog::class);
    $product = Product::where('name', 'Es Teh Manis')->sole();
    $ingredient = InventoryItem::where('name', 'Teh')->sole();
    $catalog->saveProduct($product->id, [
        'name' => $product->name,
        'category_id' => $product->category_id,
        'price' => 6500,
        'image_url' => 'products/edited.jpg',
    ]);
    $catalog->saveIngredient($ingredient->id, [
        'name' => $ingredient->name,
        'unit' => $ingredient->unit,
        'current_stock' => '200.00',
        'low_stock_threshold' => '20.00',
    ]);
    $catalog->replaceRecipe($product->id, [
        ['inventory_item_id' => $ingredient->id, 'quantity_required' => '4.00'],
    ], $product->fresh()->version);
    $events = DB::table('domain_outbox')->count();
    $recipes = Recipe::count();

    $this->seed(OemahTahuMenuSeeder::class);

    expect(Product::count())->toBe(238)
        ->and(Category::count())->toBe(28)
        ->and(InventoryItem::count())->toBe(137)
        ->and(Recipe::count())->toBe($recipes)
        ->and(DB::table('domain_outbox')->count())->toBe($events)
        ->and($product->fresh()->price)->toBe('6500.00')
        ->and($product->fresh()->image_url)->toBe('products/edited.jpg')
        ->and($product->recipes()->sole()->quantity_required)->toBe('4.00')
        ->and($ingredient->fresh()->current_stock)->toBe('200.00')
        ->and($ingredient->fresh()->low_stock_threshold)->toBe('20.00');
});

test('incompatible existing ingredient units roll back the entire menu import', function () {
    $ingredient = InventoryItem::factory()->create(['name' => 'Teh', 'unit' => 'kg']);

    expect(fn () => $this->seed(OemahTahuMenuSeeder::class))
        ->toThrow(RuntimeException::class, 'Bahan perlu diperiksa sebelum impor: Teh');

    expect(InventoryItem::count())->toBe(1)
        ->and($ingredient->fresh()->unit)->toBe('kg')
        ->and(Category::count())->toBe(0)
        ->and(Product::count())->toBe(0)
        ->and(Recipe::count())->toBe(0)
        ->and(DB::table('domain_outbox')->count())->toBe(0);
});
