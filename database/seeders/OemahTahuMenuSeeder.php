<?php

namespace Database\Seeders;

use App\Domain\Catalog\ManageCatalog;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class OemahTahuMenuSeeder extends Seeder
{
    public function run(ManageCatalog $catalog): void
    {
        $menu = json_decode(File::get(database_path('seeders/data/oemah-tahu-menu.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->command?->warn($menu['source']['recipe_status']);

        DB::transaction(function () use ($catalog, $menu): void {
            $ingredients = [];
            foreach ($menu['ingredients'] as $input) {
                $ingredient = InventoryItem::withTrashed()->where('name', $input['name'])->lockForUpdate()->first();

                if ($ingredient && ($ingredient->trashed() || $ingredient->unit !== $input['unit'])) {
                    throw new RuntimeException('Bahan perlu diperiksa sebelum impor: '.$input['name']);
                }

                $ingredients[$input['name']] = $ingredient ?? $catalog->saveIngredient(null, [
                    ...$input,
                    'current_stock' => '0.00',
                    'low_stock_threshold' => '0.00',
                ]);
            }

            $categories = [];
            foreach ($menu['products'] as $input) {
                $slug = Str::slug($input['name']);
                if (Product::withTrashed()->where('slug', $slug)->orWhere('name', $input['name'])->exists()) {
                    continue;
                }

                $categoryName = $input['category'];
                $categories[$categoryName] ??= Category::query()->where('slug', Str::slug($categoryName))->first()
                    ?? $catalog->saveCategory(null, $categoryName);

                $product = $catalog->saveProduct(null, [
                    'name' => $input['name'],
                    'category_id' => $categories[$categoryName]->id,
                    'price' => $input['price'],
                    'image_url' => null,
                ]);

                $components = [];
                foreach ($input['recipe'] as $ingredientName => $quantity) {
                    $components[] = [
                        'inventory_item_id' => $ingredients[$ingredientName]->id,
                        'quantity_required' => $quantity,
                    ];
                }

                $catalog->replaceRecipe($product->id, $components, $product->version);
            }
        }, 3);
    }
}
