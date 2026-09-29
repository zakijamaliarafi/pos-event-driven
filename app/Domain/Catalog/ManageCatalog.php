<?php

namespace App\Domain\Catalog;

use App\Domain\Events\RecordDomainEvent;
use App\Models\Category;
use App\Models\Discount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManageCatalog
{
    public function __construct(private RecordDomainEvent $events) {}

    public function saveCategory(?int $id, string $name): Category
    {
        $name = Validator::make(['name' => $name], ['name' => 'required|string|max:255'])->validate()['name'];

        return DB::transaction(function () use ($id, $name): Category {
            $category = $id ? Category::query()->lockForUpdate()->findOrFail($id) : new Category;
            $category->fill(['name' => $name, 'slug' => Str::slug($name)]);
            $category->save();
            $this->events->record($category, $id ? 'CategoryUpdated' : 'CategoryCreated', ['category_id' => $category->id, 'name' => $category->name]);

            return $category;
        });
    }

    public function deleteCategory(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $category = Category::query()->lockForUpdate()->findOrFail($id);

            if ($category->products()->exists()) {
                throw ValidationException::withMessages(['category' => __('catalog.errors.category_has_products')]);
            }

            $this->events->record($category, 'CategoryDeleted', ['category_id' => $id]);
            $category->delete();
        });
    }

    /** @param array<string, mixed> $input */
    public function saveProduct(?int $id, array $input): Product
    {
        $data = Validator::make($input, [
            'name' => 'required|string|max:255',
            'category_id' => 'required|integer|exists:categories,id',
            'price' => 'required|numeric|min:0',
            'image_url' => 'nullable|string|max:255',
        ])->validate();

        return DB::transaction(function () use ($id, $data): Product {
            $product = $id ? Product::query()->lockForUpdate()->findOrFail($id) : new Product;
            $product->fill($data);

            if (! $id) {
                $product->current_stock = 0;
                $product->low_stock_threshold = 0;
                $product->is_available = false;
            }

            $product->slug = Str::slug($data['name']);
            $product->save();
            $this->events->record($product, $id ? 'ProductUpdated' : 'ProductCreated', ['product_id' => $product->id, 'name' => $product->name]);

            return $product;
        });
    }

    public function deleteProduct(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $product = Product::query()->lockForUpdate()->findOrFail($id);
            $this->events->record($product, 'ProductDeleted', ['product_id' => $id]);
            $product->delete();
        });
    }

    public function setProductStock(int $id, int $onHand, int $threshold): void
    {
        Validator::make(compact('onHand', 'threshold'), [
            'onHand' => 'required|integer|min:0',
            'threshold' => 'required|integer|min:0',
        ])->validate();

        DB::transaction(function () use ($id, $onHand, $threshold): void {
            $product = Product::query()->lockForUpdate()->findOrFail($id);

            if ($onHand < $product->reserved_stock) {
                throw ValidationException::withMessages(['stock' => __('catalog.errors.stock_below_reserved')]);
            }

            $product->current_stock = $onHand;
            $product->low_stock_threshold = $threshold;
            $product->is_available = $onHand > 0;
            $this->events->record($product, 'ProductStockAdjusted', ['product_id' => $id, 'on_hand' => $onHand, 'reserved' => $product->reserved_stock]);
        });
    }

    /** @param array<string, mixed> $input */
    public function saveIngredient(?int $id, array $input): InventoryItem
    {
        $data = Validator::make($input, [
            'name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'current_stock' => 'required|numeric|min:0',
            'low_stock_threshold' => 'required|numeric|min:0',
        ])->validate();

        return DB::transaction(function () use ($id, $data): InventoryItem {
            $ingredient = $id ? InventoryItem::query()->lockForUpdate()->findOrFail($id) : new InventoryItem;

            if ($id && $data['current_stock'] < (float) $ingredient->reserved_stock) {
                throw ValidationException::withMessages(['current_stock' => __('catalog.errors.stock_below_reserved')]);
            }

            $ingredient->fill($data);
            $ingredient->save();
            $this->events->record($ingredient, $id ? 'IngredientUpdated' : 'IngredientCreated', ['ingredient_id' => $ingredient->id, 'on_hand' => $ingredient->current_stock]);

            return $ingredient;
        });
    }

    public function deleteIngredient(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $ingredient = InventoryItem::query()->lockForUpdate()->findOrFail($id);

            if ($ingredient->recipes()->exists() || (float) $ingredient->reserved_stock > 0) {
                throw ValidationException::withMessages(['ingredient' => __('catalog.errors.ingredient_has_recipes')]);
            }

            $this->events->record($ingredient, 'IngredientDeleted', ['ingredient_id' => $id]);
            $ingredient->delete();
        });
    }

    public function saveRecipe(int $productId, int $ingredientId, float $quantity): void
    {
        Validator::make(compact('ingredientId', 'quantity'), [
            'ingredientId' => 'required|exists:inventory_items,id',
            'quantity' => 'required|numeric|gt:0',
        ])->validate();

        DB::transaction(function () use ($productId, $ingredientId, $quantity): void {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            Recipe::updateOrCreate(
                ['product_id' => $productId, 'inventory_item_id' => $ingredientId],
                ['quantity_required' => $quantity],
            );
            $this->events->record($product, 'RecipeUpdated', ['product_id' => $productId, 'ingredient_id' => $ingredientId, 'quantity' => $quantity]);
        });
    }

    public function deleteRecipe(int $productId, int $ingredientId): void
    {
        DB::transaction(function () use ($productId, $ingredientId): void {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            Recipe::where('product_id', $productId)->where('inventory_item_id', $ingredientId)->delete();
            $this->events->record($product, 'RecipeRemoved', ['product_id' => $productId, 'ingredient_id' => $ingredientId]);
        });
    }

    /** @param array<string, mixed> $input @param array<int, int> $productIds */
    public function saveDiscount(?int $id, array $input, array $productIds): Discount
    {
        $data = Validator::make($input, [
            'name' => 'required|string|max:255',
            'discount_type' => 'required|in:percentage,fixed_amount',
            'discount_value' => 'required|numeric|min:0',
            'schedule_type' => 'required|in:interval,routine',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'routine_day' => 'nullable|integer|between:0,6',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'is_active' => 'required|boolean',
        ])->validate();
        Validator::make(['products' => $productIds], ['products' => 'array', 'products.*' => 'integer|exists:products,id'])->validate();

        return DB::transaction(function () use ($id, $data, $productIds): Discount {
            $discount = $id ? Discount::query()->lockForUpdate()->findOrFail($id) : new Discount;
            $discount->fill($data);
            $discount->save();
            $discount->products()->sync($productIds);
            $this->events->record($discount, $id ? 'DiscountUpdated' : 'DiscountCreated', ['discount_id' => $discount->id, 'product_ids' => $productIds]);

            return $discount;
        });
    }

    public function deleteDiscount(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $discount = Discount::query()->lockForUpdate()->findOrFail($id);
            $this->events->record($discount, 'DiscountDeleted', ['discount_id' => $id]);
            $discount->delete();
        });
    }

    public function toggleDiscount(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $discount = Discount::query()->lockForUpdate()->findOrFail($id);
            $discount->is_active = ! $discount->is_active;
            $this->events->record($discount, 'DiscountToggled', ['discount_id' => $id, 'is_active' => $discount->is_active]);
        });
    }
}
