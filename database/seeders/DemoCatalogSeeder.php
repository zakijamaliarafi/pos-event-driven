<?php

namespace Database\Seeders;

use App\Domain\Catalog\ManageCatalog;
use App\Models\Category;
use Illuminate\Database\Seeder;

class DemoCatalogSeeder extends Seeder
{
    public function run(ManageCatalog $catalog): void
    {
        if (Category::where('slug', 'general')->exists()) {
            return;
        }

        $category = $catalog->saveCategory(null, 'General');
        $product = $catalog->saveProduct(null, [
            'name' => 'Prepared Item',
            'category_id' => $category->id,
            'price' => 25000,
            'image_url' => null,
        ]);
        $catalog->setProductStock($product->id, 50, 5);
        $ingredient = $catalog->saveIngredient(null, [
            'name' => 'Base Ingredient',
            'unit' => 'g',
            'current_stock' => 500,
            'low_stock_threshold' => 50,
        ]);
        $catalog->saveRecipe($product->id, $ingredient->id, 10);
    }
}
