<?php

namespace App\Domain\Catalog;

use App\Domain\Events\RecordDomainEvent;
use App\Models\Category;
use App\Models\Discount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ManageCatalog
{
    public function __construct(private RecordDomainEvent $events) {}

    public function saveCategory(?int $id, string $name): Category
    {
        $name = Validator::make(['name' => $name], ['name' => 'required|string|max:255'])->validate()['name'];

        return DB::transaction(function () use ($id, $name): Category {
            $category = $id ? Category::query()->lockForUpdate()->findOrFail($id) : new Category;
            $slug = Str::slug($name);
            $deletedCategory = Category::onlyTrashed()->where('slug', $slug)->lockForUpdate()->first();

            if ($deletedCategory) {
                $deletedCategory->name = $name;
                $deletedCategory->restore();
                $this->events->record($deletedCategory, 'CategoryRestored', ['category_id' => $deletedCategory->id, 'name' => $deletedCategory->name]);

                if ($id !== null) {
                    foreach ($category->products()->withTrashed()->orderBy('id')->lockForUpdate()->get() as $product) {
                        $product->category_id = $deletedCategory->id;
                        $product->save();
                        $this->events->record($product, 'ProductUpdated', ['product_id' => $product->id, 'name' => $product->name, 'category_id' => $deletedCategory->id]);
                    }

                    $this->events->record($category, 'CategoryDeleted', ['category_id' => $category->id]);
                    $category->delete();
                }

                return $deletedCategory;
            }
            Validator::make(['name' => $slug], [
                'name' => [Rule::unique('categories', 'slug')->ignore($category)],
            ], ['name.unique' => __('catalog.errors.category_name_taken')])->validate();
            $category->fill(['name' => $name, 'slug' => $slug]);

            try {
                $category->save();
            } catch (UniqueConstraintViolationException $exception) {
                throw ValidationException::withMessages(['name' => __('catalog.errors.category_name_taken')]);
            }
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

    /** @param array<string, mixed> $input */
    public function saveIngredient(?int $id, array $input): InventoryItem
    {
        $data = Validator::make($input, [
            'name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'current_stock' => ['required', 'numeric', 'between:0,99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'low_stock_threshold' => ['required', 'numeric', 'between:0,99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], [
            'name.*' => __('catalog.errors.ingredient_name_invalid'),
            'unit.*' => __('catalog.errors.ingredient_unit_invalid'),
            'current_stock.*' => __('catalog.errors.ingredient_quantity_invalid'),
            'low_stock_threshold.*' => __('catalog.errors.ingredient_quantity_invalid'),
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

    /** @param array<int, array{inventory_item_id: int, quantity_required: string}> $components */
    public function replaceRecipe(int $productId, array $components, int $expectedVersion): void
    {
        $data = Validator::make(['components' => $components], [
            'components' => 'present|array|list',
            'components.*' => 'required|array:inventory_item_id,quantity_required',
            'components.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory_items', 'id')->whereNull('deleted_at')],
            'components.*.quantity_required' => ['required', 'numeric', 'between:0.01,999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], [
            'components.*.inventory_item_id.required' => __('catalog.errors.recipe_ingredient_required'),
            'components.*.inventory_item_id.integer' => __('catalog.errors.recipe_ingredient_missing'),
            'components.*.inventory_item_id.exists' => __('catalog.errors.recipe_ingredient_missing'),
            'components.*.inventory_item_id.distinct' => __('catalog.errors.recipe_ingredient_duplicate'),
            'components.*.quantity_required.required' => __('catalog.errors.recipe_quantity_invalid'),
            'components.*.quantity_required.numeric' => __('catalog.errors.recipe_quantity_invalid'),
            'components.*.quantity_required.between' => __('catalog.errors.recipe_quantity_invalid'),
            'components.*.quantity_required.regex' => __('catalog.errors.recipe_quantity_invalid'),
        ])->validate();

        $normalized = collect($data['components'])->map(fn (array $component): array => [
            'inventory_item_id' => (int) $component['inventory_item_id'],
            'quantity_required' => number_format((float) $component['quantity_required'], 2, '.', ''),
        ])->sortBy('inventory_item_id')->values()->all();

        DB::transaction(function () use ($productId, $normalized, $expectedVersion): void {
            $product = Product::query()->lockForUpdate()->find($productId);
            if (! $product) {
                throw ValidationException::withMessages(['product' => __('catalog.errors.recipe_product_missing')]);
            }
            if ((int) $product->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => __('catalog.errors.recipe_conflict')]);
            }

            $ingredients = InventoryItem::withTrashed()->whereIn('id', array_column($normalized, 'inventory_item_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($normalized as $component) {
                $ingredient = $ingredients->get($component['inventory_item_id']);
                if (! $ingredient || $ingredient->trashed()) {
                    throw ValidationException::withMessages(['components' => __('catalog.errors.recipe_ingredient_missing')]);
                }
            }

            $existing = $product->recipes()->orderBy('inventory_item_id')->lockForUpdate()->get()->map(fn (Recipe $recipe): array => [
                'inventory_item_id' => (int) $recipe->inventory_item_id,
                'quantity_required' => $recipe->quantity_required,
            ])->all();
            if ($existing === $normalized) {
                return;
            }

            $product->recipes()->whereNotIn('inventory_item_id', array_column($normalized, 'inventory_item_id'))->delete();
            foreach ($normalized as $component) {
                Recipe::updateOrCreate(['product_id' => $productId, 'inventory_item_id' => $component['inventory_item_id']], [
                    'quantity_required' => $component['quantity_required'],
                ]);
            }
            $this->events->record($product, 'RecipeReplaced', ['product_id' => $productId, 'components' => $normalized]);
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
        $data = $this->validateDiscount($input, $productIds);
        $productIds = array_map('intval', $productIds);

        return DB::transaction(function () use ($id, $data, $productIds): Discount {
            $discount = $id ? Discount::query()->lockForUpdate()->findOrFail($id) : new Discount;
            $this->lockDiscountProducts($productIds);
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
            if (! $discount->is_active) {
                $productIds = $discount->products()->pluck('products.id')->all();
                $input = $discount->only(['name', 'discount_type', 'discount_value', 'schedule_type', 'routine_day', 'is_active']);
                $input['start_date'] = $discount->start_date?->format('Y-m-d');
                $input['end_date'] = $discount->end_date?->format('Y-m-d');
                $input['start_time'] = $discount->start_time ? substr($discount->start_time, 0, 5) : null;
                $input['end_time'] = $discount->end_time ? substr($discount->end_time, 0, 5) : null;
                $this->validateDiscount($input, $productIds);
                $this->lockDiscountProducts($productIds);
            }
            $discount->is_active = ! $discount->is_active;
            $this->events->record($discount, 'DiscountToggled', ['discount_id' => $id, 'is_active' => $discount->is_active]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, int>  $productIds
     * @return array<string, mixed>
     */
    private function validateDiscount(array $input, array $productIds): array
    {
        $maximum = ($input['discount_type'] ?? null) === 'percentage' ? '100' : '99999999.99';
        $data = Validator::make($input, [
            'name' => 'required|string|max:255',
            'discount_type' => 'required|in:percentage,fixed_amount',
            'discount_value' => ['required', 'numeric', 'between:0.01,'.$maximum, 'regex:/^\d+(\.\d{1,2})?$/'],
            'schedule_type' => 'required|in:interval,routine',
            'start_date' => 'exclude_unless:schedule_type,interval|required|date_format:Y-m-d',
            'end_date' => 'exclude_unless:schedule_type,interval|required|date_format:Y-m-d|after_or_equal:start_date',
            'routine_day' => 'exclude_unless:schedule_type,routine|required|integer|between:0,6',
            'start_time' => 'exclude_unless:schedule_type,routine|required|date_format:H:i',
            'end_time' => 'exclude_unless:schedule_type,routine|required|date_format:H:i|after:start_time',
            'is_active' => 'required|boolean',
        ], [
            'name.*' => 'Masukkan nama promo, maksimal 255 karakter.',
            'discount_type.*' => 'Pilih jenis diskon yang tersedia.',
            'discount_value.*' => 'Masukkan nilai antara 0,01 dan '.number_format((float) $maximum, 2, ',', '.').', maksimal dua angka desimal.',
            'schedule_type.*' => 'Pilih jenis jadwal yang tersedia.',
            'start_date.*' => 'Masukkan tanggal mulai yang valid.',
            'end_date.*' => 'Tanggal selesai wajib diisi dan harus pada atau setelah tanggal mulai.',
            'routine_day.*' => 'Pilih hari untuk jadwal mingguan.',
            'start_time.*' => 'Masukkan jam mulai yang valid.',
            'end_time.*' => 'Jam selesai wajib diisi dan harus setelah jam mulai pada hari yang sama.',
            'is_active.*' => 'Status diskon tidak valid.',
        ])->validate();
        Validator::make(['products' => $productIds], [
            'products' => 'required|array|min:1',
            'products.*' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
        ], [
            'products.*' => 'Pilih minimal satu produk yang tersedia.',
            'products.*.*' => 'Produk yang dipilih harus berbeda dan masih tersedia. Periksa pilihan produk.',
        ])->validate();

        return array_replace(['start_date' => null, 'end_date' => null, 'routine_day' => null, 'start_time' => null, 'end_time' => null], $data);
    }

    /** @param array<int, int> $productIds */
    private function lockDiscountProducts(array $productIds): void
    {
        $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get(['id']);
        if ($products->count() !== count($productIds)) {
            throw ValidationException::withMessages(['products' => 'Produk yang dipilih sudah tidak tersedia. Periksa pilihan produk.']);
        }
    }
}
