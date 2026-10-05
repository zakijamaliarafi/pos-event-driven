<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array{category_id: int, name: string, slug: string, price: float, image_url: string, is_available: bool, current_stock: int, low_stock_threshold: int}
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'price' => fake()->randomFloat(2, 10000, 100000),
            'image_url' => 'products/default.jpg',
            'is_available' => true,
            'current_stock' => 0,
            'low_stock_threshold' => 0,
        ];
    }

    public function withRecipe(float $stock = 100, float $quantityRequired = 1): static
    {
        return $this->afterCreating(function (Product $product) use ($stock, $quantityRequired): void {
            $ingredient = InventoryItem::factory()->create(['current_stock' => $stock]);
            Recipe::create([
                'product_id' => $product->id,
                'inventory_item_id' => $ingredient->id,
                'quantity_required' => $quantityRequired,
            ]);
        });
    }
}
