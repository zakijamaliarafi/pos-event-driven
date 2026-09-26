<?php

use App\Domain\Catalog\ManageCatalog;
use App\Models\InventoryItem;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('catalog commands version changes and emit durable events', function () {
    $catalog = app(ManageCatalog::class);
    $category = $catalog->saveCategory(null, 'General');
    $product = $catalog->saveProduct(null, ['name' => 'Item', 'category_id' => $category->id, 'price' => 12000, 'image_url' => null]);
    $catalog->setProductStock($product->id, 5, 1);
    $ingredient = $catalog->saveIngredient(null, ['name' => 'Material', 'unit' => 'g', 'current_stock' => 20, 'low_stock_threshold' => 2]);
    $catalog->saveRecipe($product->id, $ingredient->id, 1.5);

    expect($product->fresh()->version)->toBe(3)
        ->and(Recipe::where('product_id', $product->id)->where('inventory_item_id', $ingredient->id)->count())->toBe(1)
        ->and(DB::table('domain_outbox')->where('event_name', 'RecipeUpdated')->count())->toBe(1)
        ->and(InventoryItem::find($ingredient->id)->current_stock)->toBe('20.00');
});
