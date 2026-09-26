<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
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
            'current_stock' => fake()->numberBetween(10, 100),
            'low_stock_threshold' => 5,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_available' => false,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_stock' => 0,
        ]);
    }

    public function withStock(int $stock): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_stock' => $stock,
        ]);
    }
}
