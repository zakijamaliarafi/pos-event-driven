<?php

use App\Domain\Catalog\ManageCatalog;
use App\Domain\Catalog\ProductAvailability;
use App\Models\Category;
use App\Models\Discount;
use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads, WithPagination;

    public string $section = 'products';

    #[Locked]
    public ?int $categoryId = null;

    public string $categoryName = '';

    public string $categorySearch = '';

    public bool $showCategoryModal = false;

    public string $categoryUsageFilter = '';

    #[Locked]
    /** @var array{name: string}|array{} */
    public array $originalCategoryDraft = [];

    #[Locked]
    public bool $showCategoryDiscardConfirmation = false;

    public bool $showCategoryDeleteModal = false;

    #[Locked]
    public ?int $deletingCategoryId = null;

    #[Locked]
    public string $deletingCategoryName = '';

    #[Locked]
    public ?int $productId = null;

    public bool $showProductModal = false;

    public string $productSearch = '';

    public string $productCategoryFilter = '';

    #[Locked]
    public bool $showProductDiscardConfirmation = false;

    #[Locked]
    /** @var array{name: string, category_id: ?int, price: string}|array{} */
    public array $originalProductDraft = [];

    public bool $showProductDeleteModal = false;

    #[Locked]
    public ?int $deletingProductId = null;

    public string $productName = '';

    public ?int $productCategoryId = null;

    public string $productPrice = '';

    #[Locked]
    public ?string $productImageUrl = null;

    public $productImage = null;

    #[Locked]
    public ?int $ingredientId = null;

    public string $ingredientSearch = '';

    public string $ingredientStatusFilter = '';

    public bool $showIngredientModal = false;

    #[Locked]
    public bool $showIngredientDiscardConfirmation = false;

    #[Locked]
    /** @var array<string, string> */
    public array $originalIngredientDraft = [];

    public bool $showIngredientDeleteModal = false;

    #[Locked]
    public ?int $deletingIngredientId = null;

    public string $ingredientName = '';

    public string $ingredientUnit = '';

    public string $ingredientStock = '0';

    public string $ingredientThreshold = '0';

    public ?int $recipeProductId = null;

    public ?int $recipeIngredientId = null;

    public string $recipeQuantity = '1';

    public string $recipeSearch = '';

    public string $recipeCategoryFilter = '';

    public string $recipePresenceFilter = '';

    public bool $showRecipeModal = false;

    public bool $showRecipeDiscardConfirmation = false;

    public bool $showRecipeClearConfirmation = false;

    #[Locked]
    public ?int $editingRecipeProductId = null;

    #[Locked]
    public int $recipeExpectedVersion = 0;

    #[Locked]
    public array $originalRecipeRows = [];

    #[Locked]
    public bool $recipeConflict = false;

    #[Locked]
    public string $recipeDiscardAction = 'close';

    /** @var array<string, array{inventory_item_id: string, quantity_required: string}> */
    public array $recipeRows = [];

    /** @var array<string, string> */
    public array $recipeIngredientSearch = [];

    #[Locked]
    public ?int $discountId = null;

    public string $discountSearch = '';

    public string $discountStatusFilter = '';

    public string $discountScheduleFilter = '';

    public string $discountProductSearch = '';

    public string $discountProductCategoryFilter = '';

    #[Locked]
    public array $originalDiscountDraft = [];

    #[Locked]
    public bool $showDiscountDiscardConfirmation = false;

    public bool $showDiscountDeleteModal = false;

    #[Locked]
    public ?int $deletingDiscountId = null;

    #[Locked]
    public string $deletingDiscountName = '';

    public bool $showDiscountModal = false;

    public string $discountName = '';

    public string $discountType = 'percentage';

    public string $discountValue = '0';

    public string $scheduleType = 'interval';

    public ?string $startDate = null;

    public ?string $endDate = null;

    public ?int $routineDay = null;

    public ?string $startTime = null;

    public ?string $endTime = null;

    public bool $discountActive = true;

    public array $selectedProducts = [];

    public function mount(): void
    {
        $this->section = match (request()->route()->getName()) {
            'menu.category' => 'categories',
            'menu.ingredient' => 'ingredients',
            'menu.discount' => 'discounts',
            'menu.recipes' => 'recipes',
            default => 'products',
        };
    }

    private function authorizeCategoryEditor(): void
    {
        abort_unless(auth()->user()?->hasRole('manager'), 403);
    }

    public function saveCategory(ManageCatalog $catalog): void
    {
        $this->authorizeCategoryEditor();
        abort_unless($this->showCategoryModal && $this->originalCategoryDraft !== [], 403);
        if ($this->showCategoryDiscardConfirmation) {
            return;
        }
        $this->resetValidation();
        try {
            $this->validate(['categoryName' => 'required|string|max:255'], [
                'categoryName.*' => 'Masukkan nama kategori, maksimal 255 karakter.',
            ]);
            $catalog->saveCategory($this->categoryId, $this->categoryName);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field === 'name' ? 'categoryName' : $field, $messages[0]);
            }
            $this->dispatch('category-validation-failed');
        } catch (ModelNotFoundException $exception) {
            $this->addError('categoryForm', __('catalog.errors.category_missing'));
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('categoryForm', __('catalog.errors.category_save_failed'));
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $this->closeCategoryEditor();
        $this->resetPage(pageName: 'categoriesPage');
        session()->flash('message', __('catalog.messages.category_saved'));
    }

    public function createCategory(): void
    {
        $this->authorizeCategoryEditor();
        $this->resetCategoryEditor();
        $this->originalCategoryDraft = ['name' => ''];
        $this->showCategoryModal = true;
    }

    public function editCategory(int $id): void
    {
        $this->authorizeCategoryEditor();
        $this->resetCategoryEditor();
        $this->categoryId = $id;
        $this->showCategoryModal = true;
        $this->originalCategoryDraft = ['name' => ''];
        $category = Category::find($id);
        if (! $category) {
            $this->addError('categoryForm', __('catalog.errors.category_missing'));
            return;
        }
        $this->categoryName = $category->name;
        $this->originalCategoryDraft = ['name' => $category->name];
    }

    public function closeCategoryModal(): void
    {
        $this->requestCategoryClose();
    }

    public function requestCategoryClose(): void
    {
        $this->authorizeCategoryEditor();
        if ($this->showCategoryModal && ['name' => $this->categoryName] !== $this->originalCategoryDraft) {
            $this->showCategoryDiscardConfirmation = true;
            $this->dispatch('category-confirmation-requested');
            return;
        }
        $this->closeCategoryEditor();
    }

    public function continueCategoryEditing(): void
    {
        $this->authorizeCategoryEditor();
        $this->showCategoryDiscardConfirmation = false;
        $this->dispatch('category-editing-resumed');
    }

    public function discardCategoryChanges(): void
    {
        $this->authorizeCategoryEditor();
        if ($this->showCategoryDiscardConfirmation) {
            $this->closeCategoryEditor();
        }
    }

    private function resetCategoryEditor(): void
    {
        $this->reset('categoryId', 'categoryName', 'showCategoryModal', 'originalCategoryDraft', 'showCategoryDiscardConfirmation');
        $this->resetValidation();
    }

    private function closeCategoryEditor(): void
    {
        $this->resetCategoryEditor();
        $this->dispatch('category-dialog-closed');
    }

    public function updatedCategorySearch(): void
    {
        $this->resetPage(pageName: 'categoriesPage');
    }

    public function updatedCategoryUsageFilter(): void
    {
        $this->resetPage(pageName: 'categoriesPage');
    }

    public function resetCategoryFilters(): void
    {
        $this->authorizeCategoryEditor();
        $this->reset('categorySearch', 'categoryUsageFilter');
        $this->resetPage(pageName: 'categoriesPage');
    }

    public function refreshCategories(): void
    {
        $this->authorizeCategoryEditor();
        $this->resetPage(pageName: 'categoriesPage');
    }

    public function requestCategoryDelete(int $id): void
    {
        $this->authorizeCategoryEditor();
        $this->resetValidation();
        $this->deletingCategoryId = $id;
        $this->deletingCategoryName = Category::find($id)?->name ?? 'Kategori sudah dihapus';
        $this->showCategoryDeleteModal = true;
    }

    public function closeCategoryDeleteModal(): void
    {
        $this->authorizeCategoryEditor();
        $this->reset('showCategoryDeleteModal', 'deletingCategoryId', 'deletingCategoryName');
        $this->resetValidation();
        $this->dispatch('category-dialog-closed');
    }

    public function deleteCategory(ManageCatalog $catalog): void
    {
        $this->authorizeCategoryEditor();
        abort_unless($this->showCategoryDeleteModal && $this->deletingCategoryId !== null, 403);
        $this->resetValidation();
        try {
            $catalog->deleteCategory($this->deletingCategoryId);
        } catch (ValidationException $exception) {
            $this->addError('categoryDelete', $exception->errors()['category'][0]);
            return;
        } catch (ModelNotFoundException $exception) {
            $this->addError('categoryDelete', __('catalog.errors.category_missing'));
            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('categoryDelete', __('catalog.errors.category_delete_failed'));
            return;
        }
        $this->closeCategoryDeleteModal();
        $this->resetPage(pageName: 'categoriesPage');
        session()->flash('message', __('catalog.messages.category_deleted'));
    }

    public function saveProduct(ManageCatalog $catalog): void
    {
        $this->authorizeProductEditor();
        abort_unless($this->showProductModal && $this->originalProductDraft !== [], 403);
        if ($this->showProductDiscardConfirmation) {
            return;
        }
        $this->resetValidation();
        $storedImage = null;
        try {
            $this->validate([
                'productName' => 'required|string|max:255',
                'productCategoryId' => ['required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
                'productPrice' => 'required|numeric|min:0',
                'productImage' => 'nullable|image|max:2048',
            ], [
                'productName.*' => 'Masukkan nama produk, maksimal 255 karakter.',
                'productCategoryId.*' => 'Pilih kategori yang tersedia.',
                'productPrice.*' => 'Masukkan harga berupa angka, minimal 0.',
                'productImage.*' => 'Pilih berkas gambar dengan ukuran maksimal 2 MB.',
            ]);
            if ($this->productId !== null) {
                Product::findOrFail($this->productId);
            }
            $storedImage = $this->productImage ? $this->productImage->store('products', 'public') : null;
            $catalog->saveProduct($this->productId, [
                'name' => $this->productName,
                'category_id' => $this->productCategoryId,
                'price' => $this->productPrice,
                'image_url' => $storedImage ?? $this->productImageUrl,
            ]);
        } catch (ValidationException $exception) {
            $fields = ['name' => 'productName', 'category_id' => 'productCategoryId', 'price' => 'productPrice', 'image_url' => 'productImage'];
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($fields[$field] ?? $field, $messages[0]);
            }
            $this->dispatch('product-validation-failed');
        } catch (ModelNotFoundException $exception) {
            $this->addError('productForm', __('catalog.errors.product_missing'));
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('productForm', __('catalog.errors.product_save_failed'));
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            if ($storedImage) {
                Storage::disk('public')->delete($storedImage);
            }

            return;
        }
        $this->closeProductEditor();
        $this->resetPage(pageName: 'productsPage');
        session()->flash('message', __('catalog.messages.product_saved'));
    }

    public function createProduct(): void
    {
        $this->authorizeProductEditor();
        $this->resetProductEditor();
        $this->originalProductDraft = $this->productDraft();
        $this->showProductModal = true;
    }

    public function closeProductModal(): void
    {
        $this->requestProductClose();
    }

    public function requestProductClose(): void
    {
        $this->authorizeProductEditor();
        if ($this->productDraft() !== $this->originalProductDraft || $this->productImage !== null) {
            $this->showProductDiscardConfirmation = true;
            $this->dispatch('product-confirmation-requested');

            return;
        }
        $this->closeProductEditor();
    }

    public function continueProductEditing(): void
    {
        $this->authorizeProductEditor();
        $this->showProductDiscardConfirmation = false;
        $this->dispatch('product-editing-resumed');
    }

    public function discardProductChanges(): void
    {
        $this->authorizeProductEditor();
        if ($this->showProductDiscardConfirmation) {
            $this->closeProductEditor();
        }
    }

    private function resetProductEditor(): void
    {
        $this->reset('productId', 'productName', 'productCategoryId', 'productPrice', 'productImageUrl', 'productImage',
            'showProductModal', 'showProductDiscardConfirmation', 'originalProductDraft');
        $this->resetValidation();
    }

    private function closeProductEditor(): void
    {
        $this->resetProductEditor();
        $this->dispatch('product-dialog-closed');
    }

    /** @return array{name: string, category_id: ?int, price: string} */
    private function productDraft(): array
    {
        return ['name' => $this->productName, 'category_id' => $this->productCategoryId, 'price' => $this->productPrice];
    }

    private function authorizeProductEditor(): void
    {
        abort_unless(auth()->user()?->hasRole('manager'), 403);
    }

    public function updatedProductImage(): void
    {
        $this->authorizeProductEditor();
        try {
            $this->validateOnly('productImage', ['productImage' => 'nullable|image|max:2048'], [
                'productImage.*' => 'Pilih berkas gambar dengan ukuran maksimal 2 MB.',
            ]);
        } catch (ValidationException $exception) {
            $this->dispatch('product-validation-failed');
            throw $exception;
        }
    }

    public function editProduct(int $id): void
    {
        $this->authorizeProductEditor();
        $product = Product::findOrFail($id);
        $this->resetProductEditor();
        $this->productId = $id;
        $this->productName = $product->name;
        $this->productCategoryId = $product->category_id;
        $this->productPrice = (string) $product->price;
        $this->productImageUrl = $product->image_url;
        $this->originalProductDraft = $this->productDraft();
        $this->showProductModal = true;
    }

    public function updatedProductSearch(): void
    {
        $this->resetPage(pageName: 'productsPage');
    }

    public function updatedProductCategoryFilter(): void
    {
        $this->resetPage(pageName: 'productsPage');
    }

    public function resetProductFilters(): void
    {
        $this->reset('productSearch', 'productCategoryFilter');
        $this->resetPage(pageName: 'productsPage');
    }

    public function refreshProducts(): void
    {
        $this->authorizeProductEditor();
        $this->resetPage(pageName: 'productsPage');
    }

    public function requestProductDelete(int $id): void
    {
        $this->authorizeProductEditor();
        Product::findOrFail($id);
        $this->resetValidation();
        $this->deletingProductId = $id;
        $this->showProductDeleteModal = true;
    }

    public function closeProductDeleteModal(): void
    {
        $this->authorizeProductEditor();
        $this->reset('showProductDeleteModal', 'deletingProductId');
        $this->resetValidation();
        $this->dispatch('product-dialog-closed');
    }

    public function deleteProduct(ManageCatalog $catalog): void
    {
        $this->authorizeProductEditor();
        abort_unless($this->showProductDeleteModal && $this->deletingProductId !== null, 403);
        $this->resetValidation();
        try {
            $catalog->deleteProduct($this->deletingProductId);
        } catch (ModelNotFoundException $exception) {
            $this->addError('productDelete', __('catalog.errors.product_missing'));

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('productDelete', __('catalog.errors.product_delete_failed'));

            return;
        }
        $this->closeProductDeleteModal();
        $this->resetPage(pageName: 'productsPage');
        session()->flash('message', __('catalog.messages.product_deleted'));
    }

    public function saveIngredient(ManageCatalog $catalog): void
    {
        $this->authorizeIngredientEditor();
        abort_unless($this->showIngredientModal && $this->originalIngredientDraft !== [], 403);
        if ($this->showIngredientDiscardConfirmation) {
            return;
        }
        $this->resetValidation();
        $quantityRules = ['required', 'numeric', 'between:0,99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'];
        try {
            $this->validate([
                'ingredientName' => 'required|string|max:255',
                'ingredientUnit' => 'required|string|max:50',
                'ingredientStock' => $quantityRules,
                'ingredientThreshold' => $quantityRules,
            ], [
                'ingredientName.*' => __('catalog.errors.ingredient_name_invalid'),
                'ingredientUnit.*' => __('catalog.errors.ingredient_unit_invalid'),
                'ingredientStock.*' => __('catalog.errors.ingredient_quantity_invalid'),
                'ingredientThreshold.*' => __('catalog.errors.ingredient_quantity_invalid'),
            ]);
            $catalog->saveIngredient($this->ingredientId, $this->ingredientDraft());
        } catch (ValidationException $exception) {
            $fields = ['name' => 'ingredientName', 'unit' => 'ingredientUnit', 'current_stock' => 'ingredientStock', 'low_stock_threshold' => 'ingredientThreshold'];
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($fields[$field] ?? $field, $messages[0]);
            }
            $this->dispatch('ingredient-validation-failed');

            return;
        } catch (ModelNotFoundException $exception) {
            $this->addError('ingredientForm', __('catalog.errors.ingredient_missing'));

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('ingredientForm', __('catalog.errors.ingredient_save_failed'));

            return;
        }
        $this->closeIngredientEditor();
        $this->resetPage(pageName: 'ingredientsPage');
        session()->flash('message', __('catalog.messages.ingredient_saved'));
    }

    public function createIngredient(): void
    {
        $this->authorizeIngredientEditor();
        $this->resetIngredientEditor();
        $this->originalIngredientDraft = $this->ingredientDraft();
        $this->showIngredientModal = true;
    }

    public function editIngredient(int $id): void
    {
        $this->authorizeIngredientEditor();
        $ingredient = InventoryItem::findOrFail($id);
        $this->resetIngredientEditor();
        $this->ingredientId = $id;
        $this->ingredientName = $ingredient->name;
        $this->ingredientUnit = $ingredient->unit;
        $this->ingredientStock = (string) $ingredient->current_stock;
        $this->ingredientThreshold = (string) $ingredient->low_stock_threshold;
        $this->originalIngredientDraft = $this->ingredientDraft();
        $this->showIngredientModal = true;
    }

    public function requestIngredientClose(): void
    {
        $this->authorizeIngredientEditor();
        if ($this->ingredientDraft() !== $this->originalIngredientDraft) {
            $this->showIngredientDiscardConfirmation = true;
            $this->dispatch('ingredient-confirmation-requested');

            return;
        }
        $this->closeIngredientEditor();
    }

    public function continueIngredientEditing(): void
    {
        $this->authorizeIngredientEditor();
        $this->showIngredientDiscardConfirmation = false;
        $this->dispatch('ingredient-editing-resumed');
    }

    public function discardIngredientChanges(): void
    {
        $this->authorizeIngredientEditor();
        if ($this->showIngredientDiscardConfirmation) {
            $this->closeIngredientEditor();
        }
    }

    private function resetIngredientEditor(): void
    {
        $this->reset('ingredientId', 'ingredientName', 'ingredientUnit', 'ingredientStock', 'ingredientThreshold',
            'showIngredientModal', 'showIngredientDiscardConfirmation', 'originalIngredientDraft');
        $this->resetValidation();
    }

    private function closeIngredientEditor(): void
    {
        $this->resetIngredientEditor();
        $this->dispatch('ingredient-dialog-closed');
    }

    /** @return array{name: string, unit: string, current_stock: string, low_stock_threshold: string} */
    private function ingredientDraft(): array
    {
        return ['name' => $this->ingredientName, 'unit' => $this->ingredientUnit,
            'current_stock' => $this->ingredientStock, 'low_stock_threshold' => $this->ingredientThreshold];
    }

    private function authorizeIngredientEditor(): void
    {
        abort_unless(auth()->user()?->hasRole('manager'), 403);
    }

    public function updatedIngredientSearch(): void
    {
        $this->resetPage(pageName: 'ingredientsPage');
    }

    public function updatedIngredientStatusFilter(): void
    {
        $this->resetPage(pageName: 'ingredientsPage');
    }

    public function resetIngredientFilters(): void
    {
        $this->reset('ingredientSearch', 'ingredientStatusFilter');
        $this->resetPage(pageName: 'ingredientsPage');
    }

    public function refreshIngredients(): void
    {
        $this->authorizeIngredientEditor();
        $this->resetPage(pageName: 'ingredientsPage');
    }

    public function requestIngredientDelete(int $id): void
    {
        $this->authorizeIngredientEditor();
        InventoryItem::findOrFail($id);
        $this->resetValidation();
        $this->deletingIngredientId = $id;
        $this->showIngredientDeleteModal = true;
    }

    public function closeIngredientDeleteModal(): void
    {
        $this->authorizeIngredientEditor();
        $this->reset('showIngredientDeleteModal', 'deletingIngredientId');
        $this->resetValidation();
        $this->dispatch('ingredient-dialog-closed');
    }

    public function deleteIngredient(ManageCatalog $catalog): void
    {
        $this->authorizeIngredientEditor();
        abort_unless($this->showIngredientDeleteModal && $this->deletingIngredientId !== null, 403);
        $this->resetValidation();
        try {
            $catalog->deleteIngredient($this->deletingIngredientId);
        } catch (ValidationException $exception) {
            $this->addError('ingredientDelete', $exception->errors()['ingredient'][0]);

            return;
        } catch (ModelNotFoundException $exception) {
            $this->addError('ingredientDelete', __('catalog.errors.ingredient_missing'));

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('ingredientDelete', __('catalog.errors.ingredient_delete_failed'));

            return;
        }
        $this->closeIngredientDeleteModal();
        $this->resetPage(pageName: 'ingredientsPage');
        session()->flash('message', __('catalog.messages.ingredient_deleted'));
    }

    public function saveRecipe(ManageCatalog $catalog): void
    {
        $this->authorizeRecipeEditor();
        $catalog->saveRecipe($this->recipeProductId, $this->recipeIngredientId, (float) $this->recipeQuantity);
        $this->reset('recipeProductId', 'recipeIngredientId', 'recipeQuantity');
        session()->flash('message', __('catalog.messages.recipe_saved'));
    }

    public function deleteRecipe(int $productId, int $ingredientId, ManageCatalog $catalog): void
    {
        $this->authorizeRecipeEditor();
        $catalog->deleteRecipe($productId, $ingredientId);
    }

    private function authorizeRecipeEditor(): void
    {
        abort_unless(auth()->user()?->hasRole('manager'), 403);
    }

    public function updatedRecipeSearch(): void
    {
        $this->resetPage(pageName: 'recipesPage');
    }

    public function updatedRecipeCategoryFilter(): void
    {
        $this->resetPage(pageName: 'recipesPage');
    }

    public function updatedRecipePresenceFilter(): void
    {
        $this->resetPage(pageName: 'recipesPage');
    }

    public function resetRecipeFilters(): void
    {
        $this->reset('recipeSearch', 'recipeCategoryFilter', 'recipePresenceFilter');
        $this->resetPage(pageName: 'recipesPage');
    }

    public function refreshRecipes(): void
    {
        $this->authorizeRecipeEditor();
        $this->resetPage(pageName: 'recipesPage');
    }

    public function editProductRecipe(int $productId): void
    {
        $this->authorizeRecipeEditor();
        $product = Product::with('recipes')->findOrFail($productId);
        $this->resetValidation();
        $this->reset('recipeRows', 'recipeIngredientSearch', 'recipeConflict', 'showRecipeDiscardConfirmation', 'showRecipeClearConfirmation');
        $this->editingRecipeProductId = $product->id;
        $this->recipeExpectedVersion = (int) $product->version;
        foreach ($product->recipes->sortBy('inventory_item_id') as $recipe) {
            $this->recipeRows[(string) Str::uuid()] = [
                'inventory_item_id' => (string) $recipe->inventory_item_id,
                'quantity_required' => $recipe->quantity_required,
            ];
        }
        $this->originalRecipeRows = $this->recipeRows;
        $this->showRecipeModal = true;
    }

    public function addRecipeRow(): void
    {
        $this->authorizeRecipeEditor();
        $key = (string) Str::uuid();
        $this->recipeRows[$key] = ['inventory_item_id' => '', 'quantity_required' => '1'];
        $this->showRecipeClearConfirmation = false;
        $this->dispatch('recipe-row-added', key: $key);
    }

    public function removeRecipeRow(string $key): void
    {
        $this->authorizeRecipeEditor();
        unset($this->recipeRows[$key], $this->recipeIngredientSearch[$key]);
        $this->resetValidation();
        $this->showRecipeClearConfirmation = false;
    }

    public function requestRecipeClose(): void
    {
        $this->authorizeRecipeEditor();
        if ($this->recipeRows !== $this->originalRecipeRows) {
            $this->recipeDiscardAction = 'close';
            $this->showRecipeClearConfirmation = false;
            $this->showRecipeDiscardConfirmation = true;
            $this->dispatch('recipe-confirmation-requested');

            return;
        }
        $this->closeRecipeEditor();
    }

    public function requestRecipeReload(): void
    {
        $this->authorizeRecipeEditor();
        $this->recipeDiscardAction = 'reload';
        $this->showRecipeClearConfirmation = false;
        $this->showRecipeDiscardConfirmation = true;
        $this->dispatch('recipe-confirmation-requested');
    }

    public function discardRecipeChanges(): void
    {
        $this->authorizeRecipeEditor();
        if (! $this->showRecipeDiscardConfirmation) {
            return;
        }
        if ($this->recipeDiscardAction === 'reload') {
            if (! Product::whereKey($this->editingRecipeProductId)->exists()) {
                $this->addError('recipeForm', __('catalog.errors.recipe_product_missing'));
                $this->showRecipeDiscardConfirmation = false;

                return;
            }
            $this->editProductRecipe($this->editingRecipeProductId);

            return;
        }
        $this->closeRecipeEditor();
    }

    public function continueRecipeEditing(): void
    {
        $this->authorizeRecipeEditor();
        $this->reset('showRecipeDiscardConfirmation', 'showRecipeClearConfirmation');
    }

    private function closeRecipeEditor(): void
    {
        $productId = $this->editingRecipeProductId;
        $this->reset('showRecipeModal', 'showRecipeDiscardConfirmation', 'showRecipeClearConfirmation', 'editingRecipeProductId',
            'recipeExpectedVersion', 'recipeRows', 'originalRecipeRows', 'recipeIngredientSearch', 'recipeConflict', 'recipeDiscardAction');
        $this->resetValidation();
        $this->dispatch('recipe-editor-closed', productId: $productId);
    }

    public function saveWholeRecipe(ManageCatalog $catalog): void
    {
        $this->authorizeRecipeEditor();
        abort_unless($this->showRecipeModal && $this->editingRecipeProductId !== null, 403);
        $this->resetValidation();
        $this->validate([
            'recipeRows' => 'present|array',
            'recipeRows.*' => 'required|array:inventory_item_id,quantity_required',
            'recipeRows.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory_items', 'id')->whereNull('deleted_at')],
            'recipeRows.*.quantity_required' => ['required', 'numeric', 'between:0.01,999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], [
            'recipeRows.*.inventory_item_id.required' => __('catalog.errors.recipe_ingredient_required'),
            'recipeRows.*.inventory_item_id.integer' => __('catalog.errors.recipe_ingredient_missing'),
            'recipeRows.*.inventory_item_id.exists' => __('catalog.errors.recipe_ingredient_missing'),
            'recipeRows.*.inventory_item_id.distinct' => __('catalog.errors.recipe_ingredient_duplicate'),
            'recipeRows.*.quantity_required.*' => __('catalog.errors.recipe_quantity_invalid'),
        ]);
        if ($this->recipeRows === [] && ! $this->showRecipeClearConfirmation) {
            $this->showRecipeClearConfirmation = true;
            $this->dispatch('recipe-confirmation-requested');

            return;
        }
        try {
            $catalog->replaceRecipe($this->editingRecipeProductId, array_values($this->recipeRows), $this->recipeExpectedVersion);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                if (str_starts_with($field, 'components.')) {
                    $parts = explode('.', $field);
                    $key = array_keys($this->recipeRows)[(int) $parts[1]] ?? null;
                    $field = $key !== null ? 'recipeRows.'.$key.'.'.($parts[2] ?? 'inventory_item_id') : 'recipeForm';
                } else {
                    $field = 'recipeForm';
                }
                $this->addError($field, $messages[0]);
            }
            $this->recipeConflict = isset($exception->errors()['version']);
            $this->showRecipeClearConfirmation = false;

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recipeForm', __('catalog.errors.recipe_save_failed'));
            $this->showRecipeClearConfirmation = false;

            return;
        }
        $this->closeRecipeEditor();
        $this->resetPage(pageName: 'recipesPage');
        session()->flash('message', __('catalog.messages.recipe_saved'));
    }

    public function saveDiscount(ManageCatalog $catalog): void
    {
        $this->authorizeDiscountEditor();
        abort_unless($this->showDiscountModal && $this->originalDiscountDraft !== [], 403);
        if ($this->showDiscountDiscardConfirmation) {
            return;
        }
        $this->resetValidation();
        try {
            $catalog->saveDiscount($this->discountId, [
                'name' => $this->discountName,
                'discount_type' => $this->discountType,
                'discount_value' => $this->discountValue,
                'schedule_type' => $this->scheduleType,
                'start_date' => $this->scheduleType === 'interval' ? $this->startDate : null,
                'end_date' => $this->scheduleType === 'interval' ? $this->endDate : null,
                'routine_day' => $this->scheduleType === 'routine' ? $this->routineDay : null,
                'start_time' => $this->scheduleType === 'routine' ? $this->startTime : null,
                'end_time' => $this->scheduleType === 'routine' ? $this->endTime : null,
                'is_active' => $this->discountActive,
            ], $this->selectedProducts);
        } catch (ValidationException $exception) {
            $fields = [
                'name' => 'discountName', 'discount_type' => 'discountType', 'discount_value' => 'discountValue',
                'schedule_type' => 'scheduleType', 'start_date' => 'startDate', 'end_date' => 'endDate',
                'routine_day' => 'routineDay', 'start_time' => 'startTime', 'end_time' => 'endTime',
                'is_active' => 'discountActive', 'products' => 'selectedProducts',
            ];
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'products.') ? 'selectedProducts' : ($fields[$field] ?? $field), $messages[0]);
            }
            $this->dispatch('discount-validation-failed');

            return;
        } catch (ModelNotFoundException $exception) {
            $this->addError('discountForm', 'Diskon sudah dihapus atau tidak ditemukan. Tutup formulir dan muat ulang halaman melalui browser.');

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('discountForm', 'Diskon gagal disimpan. Draft tetap tersimpan di formulir; silakan coba lagi.');

            return;
        }
        $this->closeDiscountEditor();
        $this->resetPage(pageName: 'discountsPage');
        session()->flash('message', __('catalog.messages.discount_saved'));
    }

    public function createDiscount(): void
    {
        $this->authorizeDiscountEditor();
        if ($this->showDiscountModal) {
            return;
        }
        $this->resetDiscountEditor();
        $this->originalDiscountDraft = $this->discountDraft();
        $this->showDiscountModal = true;
    }

    public function closeDiscountModal(): void
    {
        $this->authorizeDiscountEditor();
        if ($this->discountDraft() !== $this->originalDiscountDraft) {
            $this->showDiscountDiscardConfirmation = true;
            $this->dispatch('discount-confirmation-requested');

            return;
        }
        $this->closeDiscountEditor();
    }

    public function continueDiscountEditing(): void
    {
        $this->authorizeDiscountEditor();
        $this->showDiscountDiscardConfirmation = false;
        $this->dispatch('discount-editing-resumed');
    }

    public function discardDiscountChanges(): void
    {
        $this->authorizeDiscountEditor();
        if ($this->showDiscountDiscardConfirmation) {
            $this->closeDiscountEditor();
        }
    }

    private function resetDiscountEditor(): void
    {
        $this->reset('discountId', 'discountName', 'discountType', 'discountValue', 'scheduleType', 'startDate', 'endDate', 'routineDay', 'startTime', 'endTime', 'discountActive', 'selectedProducts', 'showDiscountModal', 'showDiscountDiscardConfirmation', 'originalDiscountDraft', 'discountProductSearch', 'discountProductCategoryFilter');
        $this->resetValidation();
    }

    private function closeDiscountEditor(): void
    {
        $this->resetDiscountEditor();
        $this->dispatch('discount-dialog-closed');
    }

    /** @return array<string, mixed> */
    private function discountDraft(): array
    {
        $products = array_map('strval', $this->selectedProducts);
        sort($products);

        return ['name' => $this->discountName, 'type' => $this->discountType, 'value' => $this->discountValue,
            'schedule' => $this->scheduleType, 'start_date' => $this->startDate, 'end_date' => $this->endDate,
            'day' => $this->routineDay, 'start_time' => $this->startTime, 'end_time' => $this->endTime,
            'active' => $this->discountActive, 'products' => $products];
    }

    private function authorizeDiscountEditor(): void
    {
        abort_unless(auth()->user()?->hasRole('manager'), 403);
    }

    public function editDiscount(int $id): void
    {
        $this->authorizeDiscountEditor();
        if ($this->showDiscountModal) {
            return;
        }
        $this->resetValidation();
        $discount = Discount::with('products')->find($id);
        if (! $discount) {
            $this->addError('discountList', 'Diskon sudah dihapus atau tidak ditemukan. Muat ulang halaman melalui browser.');

            return;
        }
        $this->resetDiscountEditor();
        $this->discountId = $id;
        $this->discountName = $discount->name;
        $this->discountType = $discount->discount_type;
        $this->discountValue = (string) $discount->discount_value;
        $this->scheduleType = $discount->schedule_type;
        $this->startDate = $discount->start_date?->format('Y-m-d');
        $this->endDate = $discount->end_date?->format('Y-m-d');
        $this->routineDay = $discount->routine_day;
        $this->startTime = $discount->start_time ? substr($discount->start_time, 0, 5) : null;
        $this->endTime = $discount->end_time ? substr($discount->end_time, 0, 5) : null;
        $this->discountActive = $discount->is_active;
        $this->selectedProducts = $discount->products->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->originalDiscountDraft = $this->discountDraft();
        $this->showDiscountModal = true;
    }

    public function toggleDiscount(int $id, ManageCatalog $catalog): void
    {
        $this->authorizeDiscountEditor();
        $this->resetValidation();
        try {
            $catalog->toggleDiscount($id);
        } catch (ValidationException $exception) {
            $this->addError('discountList', 'Diskon belum dapat diaktifkan. '.$exception->validator->errors()->first().' Gunakan Ubah untuk memperbaiki diskon.');

            return;
        } catch (ModelNotFoundException $exception) {
            $this->addError('discountList', 'Diskon sudah dihapus atau tidak ditemukan. Muat ulang halaman melalui browser.');

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('discountList', 'Status diskon gagal diubah. Silakan coba lagi.');

            return;
        }
        $this->resetPage(pageName: 'discountsPage');
        session()->flash('message', 'Status diskon diperbarui.');
    }

    public function deleteDiscount(int $id): void
    {
        $this->authorizeDiscountEditor();
        $this->resetValidation();
        $discount = Discount::find($id);
        if (! $discount) {
            $this->addError('discountList', 'Diskon sudah dihapus atau tidak ditemukan. Muat ulang halaman melalui browser.');

            return;
        }
        $this->deletingDiscountId = $id;
        $this->deletingDiscountName = $discount->name;
        $this->showDiscountDeleteModal = true;
    }

    public function closeDiscountDeleteModal(): void
    {
        $this->authorizeDiscountEditor();
        $this->reset('showDiscountDeleteModal', 'deletingDiscountId', 'deletingDiscountName');
        $this->resetValidation();
        $this->dispatch('discount-dialog-closed');
    }

    public function confirmDiscountDeletion(ManageCatalog $catalog): void
    {
        $this->authorizeDiscountEditor();
        abort_unless($this->showDiscountDeleteModal && $this->deletingDiscountId !== null, 403);
        $this->resetValidation();
        try {
            $catalog->deleteDiscount($this->deletingDiscountId);
        } catch (ModelNotFoundException $exception) {
            $this->addError('discountDelete', 'Diskon sudah dihapus atau tidak ditemukan. Tutup konfirmasi dan muat ulang halaman melalui browser.');

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('discountDelete', 'Diskon gagal dihapus. Silakan coba lagi.');

            return;
        }
        $this->closeDiscountDeleteModal();
        $this->resetPage(pageName: 'discountsPage');
        session()->flash('message', 'Diskon dihapus.');
    }

    public function updatedDiscountSearch(): void
    {
        $this->resetPage(pageName: 'discountsPage');
    }

    public function updatedDiscountStatusFilter(): void
    {
        $this->resetPage(pageName: 'discountsPage');
    }

    public function updatedDiscountScheduleFilter(): void
    {
        $this->resetPage(pageName: 'discountsPage');
    }

    public function refreshDiscounts(): void
    {
        $this->authorizeDiscountEditor();
        $this->resetValidation();
        $this->resetPage(pageName: 'discountsPage');
    }

    public function resetDiscountFilters(): void
    {
        $this->authorizeDiscountEditor();
        $this->reset('discountSearch', 'discountStatusFilter', 'discountScheduleFilter');
        $this->resetPage(pageName: 'discountsPage');
    }

    private function discountProductQuery(): Builder
    {
        return Product::query()
            ->when(trim($this->discountProductSearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->discountProductSearch).'%'))
            ->when($this->discountProductCategoryFilter !== '', fn (Builder $query): Builder => $query->where('category_id', $this->discountProductCategoryFilter))
            ->orderBy('name')->orderBy('id');
    }

    public function selectDiscountProductResults(): void
    {
        $this->authorizeDiscountEditor();
        abort_unless($this->showDiscountModal && $this->originalDiscountDraft !== [], 403);
        if (! $this->showDiscountDiscardConfirmation) {
            $this->selectedProducts = array_values(array_unique(array_merge(array_map('strval', $this->selectedProducts), $this->discountProductQuery()->pluck('id')->map(fn ($id): string => (string) $id)->all())));
        }
    }

    public function clearDiscountProductResults(): void
    {
        $this->authorizeDiscountEditor();
        abort_unless($this->showDiscountModal && $this->originalDiscountDraft !== [], 403);
        if (! $this->showDiscountDiscardConfirmation) {
            $this->selectedProducts = array_values(array_diff(array_map('strval', $this->selectedProducts), $this->discountProductQuery()->pluck('id')->map(fn ($id): string => (string) $id)->all()));
        }
    }

    public function with(): array
    {
        $discountRows = $this->section === 'discounts'
            ? Discount::query()->withCount('products')
                ->when(trim($this->discountSearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->discountSearch).'%'))
                ->when(in_array($this->discountStatusFilter, ['active', 'inactive']), fn (Builder $query): Builder => $query->where('is_active', $this->discountStatusFilter === 'active'))
                ->when(in_array($this->discountScheduleFilter, ['interval', 'routine']), fn (Builder $query): Builder => $query->where('schedule_type', $this->discountScheduleFilter))
                ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'discountsPage')
            : null;
        $discountOverview = $discountRows?->getCollection()->mapWithKeys(function (Discount $discount): array {
            $complete = $discount->schedule_type === 'interval'
                ? $discount->start_date !== null && $discount->end_date !== null && $discount->end_date->gte($discount->start_date)
                : $discount->routine_day !== null && $discount->start_time !== null && $discount->end_time !== null && $discount->end_time > $discount->start_time;
            $schedule = $discount->schedule_type === 'interval'
                ? ($discount->start_date?->translatedFormat('d M Y') ?? '—').' – '.($discount->end_date?->translatedFormat('d M Y') ?? '—')
                : (['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][$discount->routine_day] ?? '—').' ('.($discount->start_time ? substr($discount->start_time, 0, 5) : '—').' – '.($discount->end_time ? substr($discount->end_time, 0, 5) : '—').')';

            return [$discount->id => [
                'value' => $discount->discount_type === 'percentage' ? number_format((float) $discount->discount_value, 2, ',', '.').'%' : 'Rp '.number_format((float) $discount->discount_value, fmod((float) $discount->discount_value, 1.0) === 0.0 ? 0 : 2, ',', '.'),
                'schedule' => $schedule, 'complete' => $complete,
            ]];
        })->all() ?? [];
        $productRows = $this->section === 'products'
            ? Product::with(['category', 'recipes.inventoryItem'])
                ->when(trim($this->productSearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->productSearch).'%'))
                ->when($this->productCategoryFilter !== '', fn (Builder $query): Builder => $query->where('category_id', $this->productCategoryFilter))
                ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'productsPage')
            : null;
        $productAvailability = new ProductAvailability;
        $productOverview = $productRows?->getCollection()->mapWithKeys(fn (Product $product): array => [$product->id => [
            'status' => $product->recipes->isEmpty() ? 'Belum ada resep' : ($productAvailability->hasValidRecipe($product) ? 'Resep tersedia' : 'Resep tidak valid'),
            'portions' => $productAvailability->availableQuantity($product),
        ]])->all() ?? [];
        $ingredientRows = $this->section === 'ingredients'
            ? InventoryItem::query()->withCount('recipes')
                ->when(trim($this->ingredientSearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->ingredientSearch).'%'))
                ->when($this->ingredientStatusFilter === 'empty', fn (Builder $query): Builder => $query->whereRaw('current_stock - reserved_stock <= 0'))
                ->when($this->ingredientStatusFilter === 'low', fn (Builder $query): Builder => $query->whereRaw('current_stock - reserved_stock > 0 AND current_stock - reserved_stock <= low_stock_threshold'))
                ->when($this->ingredientStatusFilter === 'sufficient', fn (Builder $query): Builder => $query->whereRaw('current_stock - reserved_stock > 0 AND current_stock - reserved_stock > low_stock_threshold'))
                ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'ingredientsPage')
            : null;
        $ingredientOverview = $ingredientRows?->getCollection()->mapWithKeys(function (InventoryItem $ingredient): array {
            $available = max(0, ProductAvailability::hundredths($ingredient->current_stock) - ProductAvailability::hundredths($ingredient->reserved_stock));

            return [$ingredient->id => [
                'available' => number_format($available / 100, 2, ',', '.'),
                'status' => $available === 0 ? 'Habis' : ($available <= ProductAvailability::hundredths($ingredient->low_stock_threshold) ? 'Rendah' : 'Cukup'),
            ]];
        })->all() ?? [];
        $recipeRows = $this->section === 'recipes'
            ? Product::with(['category', 'recipes.inventoryItem'])
                ->when(trim($this->recipeSearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->recipeSearch).'%'))
                ->when($this->recipeCategoryFilter !== '', fn (Builder $query): Builder => $query->where('category_id', $this->recipeCategoryFilter))
                ->when($this->recipePresenceFilter === 'with', fn (Builder $query): Builder => $query->has('recipes'))
                ->when($this->recipePresenceFilter === 'without', fn (Builder $query): Builder => $query->doesntHave('recipes'))
                ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'recipesPage')
            : null;
        $availability = new ProductAvailability;
        $recipeOverview = $recipeRows?->getCollection()->mapWithKeys(fn (Product $product): array => [$product->id => [
            'status' => $product->recipes->isEmpty() ? 'Belum ada resep' : ($availability->hasValidRecipe($product) ? 'Resep tersedia' : 'Resep tidak valid'),
            'portions' => $availability->availableQuantity($product),
        ]])->all() ?? [];

        return [
            'productRows' => $productRows,
            'productOverview' => $productOverview,
            'hasProducts' => $this->section === 'products' && Product::exists(),
            'deletingProduct' => $this->showProductDeleteModal ? Product::find($this->deletingProductId) : null,
            'ingredientRows' => $ingredientRows,
            'ingredientOverview' => $ingredientOverview,
            'hasIngredients' => $this->section === 'ingredients' && InventoryItem::exists(),
            'editingIngredient' => $this->showIngredientModal && $this->ingredientId !== null ? InventoryItem::find($this->ingredientId) : null,
            'deletingIngredient' => $this->showIngredientDeleteModal ? InventoryItem::withCount('recipes')->find($this->deletingIngredientId) : null,
            'recipeProducts' => $recipeRows,
            'recipeOverview' => $recipeOverview,
            'hasRecipeProducts' => $this->section === 'recipes' && Product::exists(),
            'editingRecipeProduct' => $this->showRecipeModal ? Product::with('category')->find($this->editingRecipeProductId) : null,
            'categories' => in_array($this->section, ['ingredients', 'categories']) ? collect() : Category::orderBy('name')->get(),
            'hasCategories' => $this->section === 'categories' && Category::exists(),
            'deletingCategory' => $this->showCategoryDeleteModal ? Category::withCount('products')->find($this->deletingCategoryId) : null,
            'categoryRows' => $this->section === 'categories'
                ? Category::query()->withCount('products')
                    ->when(trim($this->categorySearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->categorySearch).'%'))
                    ->when($this->categoryUsageFilter === 'used', fn (Builder $query): Builder => $query->has('products'))
                    ->when($this->categoryUsageFilter === 'empty', fn (Builder $query): Builder => $query->doesntHave('products'))
                    ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'categoriesPage')
                : null,
            'products' => $this->section === 'discounts' ? Product::with('category')->orderBy('name')->orderBy('id')->get() : collect(),
            'ingredients' => $this->section === 'recipes' ? InventoryItem::orderBy('name')->get() : collect(),
            'discounts' => $discountRows ?? collect(),
            'discountRows' => $discountRows,
            'discountOverview' => $discountOverview,
            'hasDiscounts' => $this->section === 'discounts' && Discount::exists(),
            'discountProductResults' => $this->section === 'discounts' && $this->showDiscountModal ? $this->discountProductQuery()->get() : collect(),
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        @if(! in_array($section, ['categories', 'products', 'discounts', 'recipes', 'ingredients']))
        <h1 class="text-3xl font-bold">{{ match ($section) {
            'categories' => 'Kelola Kategori',
            'ingredients' => 'Kelola Bahan',
            'recipes' => 'Kelola Resep',
            'discounts' => 'Kelola Diskon',
            default => 'Kelola Produk',
        } }}</h1>
        @endif
        @if(session('message'))<div role="status" class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ session('message') }}</div>@endif
        @if($errors->any() && ! $showCategoryModal && ! $showCategoryDeleteModal && ! $showProductModal && ! $showProductDeleteModal && ! $showDiscountModal && ! $showDiscountDeleteModal && ! $showRecipeModal && ! $showIngredientModal && ! $showIngredientDeleteModal)<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $errors->first() }}</div>@endif

        @if($section === 'categories')
            <section class="min-w-0 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm sm:p-8" aria-labelledby="categories-heading">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0"><h1 id="categories-heading" tabindex="-1" class="text-3xl font-extrabold tracking-tight">Kelola Kategori</h1><p class="mt-2 text-pos-muted">Atur kelompok menu dan lihat jumlah produk dalam setiap kategori.</p></div>
                    <div class="flex flex-wrap gap-3">
                        <flux:button data-category-open="new" wire:click="createCategory" wire:loading.attr="disabled" variant="primary" icon="plus" class="min-h-11">Tambah kategori</flux:button>
                    </div>
                </div>
                <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto] lg:items-end">
                    <flux:input wire:model.live.debounce.300ms="categorySearch" label="Cari kategori" icon="magnifying-glass" placeholder="Cari kategori..." />
                    <flux:select wire:model.live="categoryUsageFilter" label="Penggunaan produk"><option value="">Semua</option><option value="used">Berisi produk</option><option value="empty">Tanpa produk</option></flux:select>
                    @if(trim($categorySearch) !== '' || $categoryUsageFilter !== '')<flux:button wire:click="resetCategoryFilters" variant="ghost" class="min-h-11">Reset filter</flux:button>@endif
                </div>
                <p wire:loading wire:target="categorySearch,categoryUsageFilter,resetCategoryFilters,refreshCategories,nextPage,previousPage" role="status" class="mt-3 text-sm text-pos-muted">Memuat kategori...</p>
                @if($categoryRows->isEmpty())
                    <div class="mt-6 rounded-xl border border-dashed border-pos-border p-8 text-center">
                        <p class="font-semibold">{{ $hasCategories ? 'Tidak ada kategori yang cocok dengan pencarian.' : 'Belum ada kategori.' }}</p>
                        <p class="mt-2 text-sm text-pos-muted">{{ $hasCategories ? 'Coba nama lain atau reset filter penggunaan produk.' : 'Tambahkan kategori untuk mengelompokkan produk pada menu.' }}</p>
                        @if($hasCategories)<flux:button wire:click="resetCategoryFilters" class="mt-4 min-h-11">Reset filter</flux:button>
                        @else<flux:button data-category-open="new" wire:click="createCategory" variant="primary" class="mt-4 min-h-11">Tambah kategori</flux:button>@endif
                    </div>
                @else
                    <div class="mt-6 hidden lg:block">
                        <table class="w-full table-fixed text-left"><thead class="border-b border-pos-border text-xs font-bold uppercase tracking-widest text-pos-muted"><tr><th scope="col" class="w-[30%] py-4 pr-4">Nama kategori</th><th scope="col" class="w-[25%] py-4 pr-4">Slug</th><th scope="col" class="w-[15%] py-4 pr-4">Jumlah produk</th><th scope="col" class="w-[30%] py-4 text-right">Aksi</th></tr></thead>
                            <tbody class="divide-y divide-pos-border">
                                @foreach($categoryRows as $category)
                                    <tr wire:key="category-table-{{ $category->id }}" class="hover:bg-pos-soft/50"><td class="break-words py-5 pr-4 font-semibold">{{ $category->name }}</td><td class="break-all py-5 pr-4 text-sm text-pos-muted">{{ $category->slug }}</td><td class="py-5 pr-4 tabular-nums">{{ $category->products_count }} produk</td><td class="py-5"><div class="flex flex-wrap justify-end gap-2">
                                        <flux:button data-category-open="edit-{{ $category->id }}" wire:click="editCategory({{ $category->id }})" wire:loading.attr="disabled" aria-label="Ubah kategori {{ $category->name }}" class="min-h-11">Ubah</flux:button>
                                        <flux:button data-category-open="delete-{{ $category->id }}" wire:click="requestCategoryDelete({{ $category->id }})" wire:loading.attr="disabled" variant="ghost" aria-label="Hapus kategori {{ $category->name }}" class="min-h-11 text-pos-danger!">Hapus</flux:button>
                                    </div></td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-6 space-y-3 lg:hidden">
                        @foreach($categoryRows as $category)
                            <article wire:key="category-card-{{ $category->id }}" class="min-w-0 rounded-xl border border-pos-border p-4"><h2 class="break-words font-semibold">{{ $category->name }}</h2><p class="mt-1 break-all text-sm text-pos-muted">Slug: {{ $category->slug }}</p><p class="mt-3 text-sm tabular-nums">{{ $category->products_count }} produk</p><div class="mt-4 grid grid-cols-2 gap-2 border-t border-pos-border pt-4">
                                <flux:button data-category-open="edit-{{ $category->id }}" wire:click="editCategory({{ $category->id }})" wire:loading.attr="disabled" aria-label="Ubah kategori {{ $category->name }}" class="min-h-11">Ubah</flux:button>
                                <flux:button data-category-open="delete-{{ $category->id }}" wire:click="requestCategoryDelete({{ $category->id }})" wire:loading.attr="disabled" variant="ghost" aria-label="Hapus kategori {{ $category->name }}" class="min-h-11 text-pos-danger!">Hapus</flux:button>
                            </div></article>
                        @endforeach
                    </div>
                @endif
                <div class="mt-6 flex flex-col gap-4 border-t border-pos-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-pos-muted" aria-live="polite">Menampilkan {{ $categoryRows->firstItem() ?? 0 }} sampai {{ $categoryRows->lastItem() ?? 0 }} dari {{ $categoryRows->total() }} kategori</p>
                    <div class="flex gap-2"><flux:button wire:click="previousPage('categoriesPage')" wire:loading.attr="disabled" :disabled="$categoryRows->onFirstPage()" class="min-h-11">Sebelumnya</flux:button><flux:button wire:click="nextPage('categoriesPage')" wire:loading.attr="disabled" :disabled="! $categoryRows->hasMorePages()" class="min-h-11">Berikutnya</flux:button></div>
                </div>
            </section>
            <flux:modal name="category-form" data-category-editor wire:model.self="showCategoryModal" :closable="false" :dismissible="false" :escapable="false" class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-lg overflow-hidden rounded-2xl border border-pos-border bg-pos-surface! !p-0 text-pos-ink dark:bg-pos-surface!">
                <form wire:submit="saveCategory" novalidate class="flex max-h-[calc(100dvh-2rem)] flex-col">
                    <div class="flex shrink-0 items-start justify-between gap-3 border-b border-pos-border p-5">
                        <div><flux:heading id="category-editor-heading" size="xl" level="2">{{ $categoryId ? 'Ubah kategori' : 'Tambah kategori' }}</flux:heading><p id="category-editor-description" class="mt-1 text-sm text-pos-muted">Slug dibuat otomatis dari nama kategori.</p></div>
                        <flux:button type="button" wire:click="requestCategoryClose" wire:loading.attr="disabled" :disabled="$showCategoryDiscardConfirmation" variant="ghost" icon="x-mark" square aria-label="Tutup formulir kategori" class="min-h-11 min-w-11" />
                    </div>
                    <div class="min-h-0 space-y-5 overflow-y-auto p-5">
                        @error('categoryForm')<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-sm text-pos-danger">{{ $message }}</div>@enderror
                        @if($showCategoryDiscardConfirmation)
                            <div data-category-confirmation role="alert" class="space-y-3 rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4"><p class="font-semibold">Buang perubahan yang belum disimpan?</p><div class="flex flex-wrap gap-2"><flux:button type="button" wire:click="continueCategoryEditing" wire:loading.attr="disabled" class="min-h-11">Lanjutkan mengedit</flux:button><flux:button type="button" wire:click="discardCategoryChanges" wire:loading.attr="disabled" variant="danger" class="min-h-11">Buang perubahan</flux:button></div></div>
                        @endif
                        <flux:input wire:model="categoryName" label="Nama kategori" badge="Wajib" required autofocus maxlength="255" :disabled="$showCategoryDiscardConfirmation" wire:loading.attr="disabled" wire:target="saveCategory" />
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-pos-border bg-pos-soft p-5">
                        <flux:button type="button" wire:click="requestCategoryClose" wire:loading.attr="disabled" :disabled="$showCategoryDiscardConfirmation" class="min-h-11">Batal</flux:button>
                        <flux:button wire:key="category-save-{{ $showCategoryDiscardConfirmation ? 'blocked' : 'allowed' }}" type="submit" variant="primary" :disabled="$showCategoryDiscardConfirmation" wire:loading.attr="disabled" class="min-h-11"><span wire:loading.remove wire:target="saveCategory">Simpan kategori</span><span wire:loading wire:target="saveCategory">Menyimpan...</span></flux:button>
                    </div>
                </form>
            </flux:modal>
            <flux:modal name="category-delete" data-category-delete wire:model.self="showCategoryDeleteModal" :closable="false" :dismissible="false" :escapable="false" class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-lg overflow-y-auto rounded-2xl border border-pos-border bg-pos-surface! text-pos-ink dark:bg-pos-surface!">
                <div class="space-y-5"><flux:heading id="category-delete-heading" size="lg" level="2">Hapus kategori?</flux:heading><p id="category-delete-description" class="break-words font-semibold">{{ $deletingCategoryName }}</p>
                    @error('categoryDelete')<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-sm text-pos-danger">{{ $message }}</div>@enderror
                    @if(! $deletingCategory)<p class="text-sm text-pos-muted">{{ __('catalog.errors.category_missing') }}</p>
                    @elseif($deletingCategory->products_count > 0)<p class="text-sm text-pos-muted">Kategori ini berisi {{ $deletingCategory->products_count }} produk. Pindahkan atau hapus produk sebelum menghapus kategori ini.</p><a href="{{ route('menu.menu') }}" class="inline-flex min-h-11 items-center text-pos-link underline">Kelola produk</a>
                    @else<p class="text-sm text-pos-muted">Kategori ini berisi 0 produk dan akan dihapus dari pilihan kategori untuk produk baru. Pesanan yang sudah dibuat tetap tersimpan.</p>@endif
                    <div class="flex flex-wrap justify-end gap-3"><flux:button autofocus type="button" wire:click="closeCategoryDeleteModal" wire:loading.attr="disabled" class="min-h-11">Batal</flux:button><flux:button wire:key="category-delete-{{ ! $deletingCategory || $deletingCategory->products_count > 0 ? 'blocked' : 'allowed' }}" type="button" wire:click="deleteCategory" variant="danger" :disabled="! $deletingCategory || $deletingCategory->products_count > 0" wire:loading.attr="disabled" class="min-h-11"><span wire:loading.remove wire:target="deleteCategory">Hapus kategori</span><span wire:loading wire:target="deleteCategory">Menghapus...</span></flux:button></div>
                </div>
            </flux:modal>
        @elseif($section === 'products')
            <section data-product-page class="min-w-0 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm sm:p-8" aria-labelledby="products-heading">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <h1 id="products-heading" tabindex="-1" class="text-3xl font-extrabold tracking-tight">Kelola Produk</h1>
                        <p class="mt-2 text-pos-muted">Atur informasi produk dan lihat kesiapan menu untuk dipesan.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <flux:button data-product-open="new" wire:click="createProduct" variant="primary" icon="plus" class="min-h-11">Tambah produk</flux:button>
                    </div>
                </div>
                <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto] lg:items-end">
                    <flux:input wire:model.live.debounce.300ms="productSearch" label="Cari produk" placeholder="Cari nama produk..." icon="magnifying-glass" />
                    <flux:select wire:model.live="productCategoryFilter" label="Kategori">
                        <option value="">Semua kategori</option>
                        @foreach($categories as $category)
                            <option wire:key="product-filter-category-{{ $category->id }}" value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </flux:select>
                    @if(trim($productSearch) !== '' || $productCategoryFilter !== '')
                        <flux:button wire:click="resetProductFilters" variant="ghost" class="min-h-11">Reset filter</flux:button>
                    @endif
                </div>
                <div class="mt-4 flex flex-col gap-2 text-sm sm:flex-row sm:items-start sm:justify-between">
                    <a href="{{ route('menu.recipes') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg font-semibold text-pos-link underline underline-offset-4">Kelola resep</a>
                </div>
                <p wire:loading.delay wire:target="productSearch,productCategoryFilter,resetProductFilters,refreshProducts,nextPage,previousPage" role="status" class="mt-3 text-sm text-pos-link">Memuat produk...</p>

                @if($productRows->isEmpty())
                    <div class="py-12 text-center">
                        <p class="font-semibold">{{ $hasProducts ? 'Tidak ada produk yang cocok dengan filter.' : 'Belum ada produk.' }}</p>
                        <p class="mt-2 text-sm text-pos-muted">{{ $hasProducts ? 'Coba nama lain atau tampilkan semua kategori.' : 'Tambahkan produk, lalu atur bahan pada halaman resep agar dapat dipesan.' }}</p>
                        <div class="mt-5">
                            @if($hasProducts)
                                <flux:button wire:click="resetProductFilters" class="min-h-11">Reset filter</flux:button>
                            @else
                                <flux:button data-product-open="empty-new" wire:click="createProduct" variant="primary" icon="plus" class="min-h-11">Tambah produk</flux:button>
                            @endif
                        </div>
                    </div>
                @else
                    <table class="mt-6 hidden w-full table-fixed text-left text-sm lg:table">
                        <thead class="border-b border-pos-border text-xs font-bold uppercase tracking-wider text-pos-muted">
                            <tr><th scope="col" class="w-[35%] pb-4">Produk</th><th scope="col" class="w-[17%] pb-4">Harga</th><th scope="col" class="w-[24%] pb-4">Resep dan porsi</th><th scope="col" class="pb-4 text-right">Aksi</th></tr>
                        </thead>
                        <tbody class="divide-y divide-pos-border">
                            @foreach($productRows as $product)
                                <tr wire:key="product-table-{{ $product->id }}" class="hover:bg-pos-soft/50">
                                    <td class="py-5 pr-4">
                                        <div class="flex items-center gap-3">
                                            @if($product->image_url)
                                                <img src="{{ Storage::disk('public')->url($product->image_url) }}" alt="" loading="lazy" class="size-12 shrink-0 rounded-xl border border-pos-border object-cover">
                                            @else
                                                <span aria-label="Tanpa gambar" class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-pos-border bg-pos-soft text-pos-muted"><flux:icon.photo class="size-5" /></span>
                                            @endif
                                            <div class="min-w-0"><p class="break-words font-semibold">{{ $product->name }}</p><p class="mt-1 break-words text-pos-muted">{{ $product->category?->name ?? 'Tanpa kategori' }}</p></div>
                                        </div>
                                    </td>
                                    <td class="break-words py-5 pr-4 font-semibold tabular-nums">{{ config('pos.currency') }} {{ number_format($product->price, 0) }}</td>
                                    <td class="py-5 pr-4">
                                        <span class="inline-flex rounded-lg border border-pos-border bg-pos-soft px-2.5 py-1 text-xs font-semibold">{{ $productOverview[$product->id]['status'] }}</span>
                                        <p class="mt-2 text-pos-muted"><span class="font-semibold tabular-nums text-pos-ink">{{ number_format($productOverview[$product->id]['portions'], 0, ',', '.') }}</span> porsi tersedia</p>
                                    </td>
                                    <td class="py-5">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <flux:button data-product-open="edit-{{ $product->id }}" wire:click="editProduct({{ $product->id }})" variant="ghost" aria-label="Ubah produk {{ $product->name }}" class="min-h-11">Ubah</flux:button>
                                            <flux:button data-product-open="delete-{{ $product->id }}" wire:click="requestProductDelete({{ $product->id }})" variant="ghost" aria-label="Hapus produk {{ $product->name }}" class="min-h-11 text-pos-danger!">Hapus</flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="mt-6 space-y-3 lg:hidden">
                        @foreach($productRows as $product)
                            <article wire:key="product-card-{{ $product->id }}" class="rounded-xl border border-pos-border p-4">
                                <div class="flex items-start gap-3">
                                    @if($product->image_url)
                                        <img src="{{ Storage::disk('public')->url($product->image_url) }}" alt="" loading="lazy" class="size-14 shrink-0 rounded-xl border border-pos-border object-cover">
                                    @else
                                        <span aria-label="Tanpa gambar" class="flex size-14 shrink-0 items-center justify-center rounded-xl border border-pos-border bg-pos-soft text-pos-muted"><flux:icon.photo class="size-5" /></span>
                                    @endif
                                    <div class="min-w-0"><h2 class="break-words font-semibold">{{ $product->name }}</h2><p class="mt-1 break-words text-sm text-pos-muted">{{ $product->category?->name ?? 'Tanpa kategori' }}</p><p class="mt-2 break-words font-semibold tabular-nums">{{ config('pos.currency') }} {{ number_format($product->price, 0) }}</p></div>
                                </div>
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <span class="rounded-lg border border-pos-border bg-pos-soft px-2.5 py-1 text-xs font-semibold">{{ $productOverview[$product->id]['status'] }}</span>
                                    <span class="text-pos-muted"><span class="font-semibold tabular-nums text-pos-ink">{{ number_format($productOverview[$product->id]['portions'], 0, ',', '.') }}</span> porsi tersedia</span>
                                </div>
                                <div class="mt-4 grid grid-cols-2 gap-2 border-t border-pos-border pt-4">
                                    <flux:button data-product-open="edit-{{ $product->id }}" wire:click="editProduct({{ $product->id }})" aria-label="Ubah produk {{ $product->name }}" class="min-h-11">Ubah</flux:button>
                                    <flux:button data-product-open="delete-{{ $product->id }}" wire:click="requestProductDelete({{ $product->id }})" variant="ghost" aria-label="Hapus produk {{ $product->name }}" class="min-h-11 text-pos-danger!">Hapus</flux:button>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
                <div class="mt-6 flex flex-col gap-3 border-t border-pos-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p role="status" class="text-sm text-pos-muted">Menampilkan {{ $productRows->firstItem() ?? 0 }} sampai {{ $productRows->lastItem() ?? 0 }} dari {{ $productRows->total() }} produk</p>
                    <div class="flex gap-2">
                        <flux:button wire:click="previousPage('productsPage')" :disabled="$productRows->onFirstPage()" class="min-h-11">Sebelumnya</flux:button>
                        <flux:button wire:click="nextPage('productsPage')" :disabled="! $productRows->hasMorePages()" class="min-h-11">Berikutnya</flux:button>
                    </div>
                </div>
            </section>

            <flux:modal name="product-form" data-product-editor wire:model.self="showProductModal" :closable="false" :dismissible="false" :escapable="false" class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-2xl overflow-hidden rounded-2xl border border-pos-border bg-pos-surface! !p-0 text-pos-ink dark:bg-pos-surface!">
                <form wire:submit="saveProduct" class="flex max-h-[calc(100dvh-2rem)] flex-col">
                    <div class="flex shrink-0 items-start justify-between gap-3 border-b border-pos-border px-5 py-5 sm:px-6">
                        <div><flux:heading id="product-editor-heading" size="xl" level="2" class="text-pos-ink">{{ $productId ? 'Ubah produk' : 'Tambah produk' }}</flux:heading><p id="product-editor-description" class="mt-1 text-sm text-pos-muted">Isi informasi produk. Kebutuhan bahan diatur terpisah pada halaman resep.</p></div>
                        <flux:button type="button" wire:click="requestProductClose" wire:loading.attr="disabled" wire:target="productImage,saveProduct" variant="ghost" icon="x-mark" square aria-label="Tutup formulir produk" class="min-h-11 min-w-11" />
                    </div>
                    <div class="min-h-0 space-y-5 overflow-y-auto p-5 sm:p-6">
                        @error('productForm')<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-sm text-pos-danger">{{ $message }}</div>@enderror
                        @if($showProductDiscardConfirmation)
                            <div data-product-confirmation role="alert" class="space-y-3 rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4">
                                <p class="font-semibold">Buang perubahan yang belum disimpan?</p><p class="text-sm">Informasi dan gambar yang diubah belum disimpan ke produk.</p>
                                <div class="flex flex-wrap gap-2"><flux:button type="button" wire:click="continueProductEditing" class="min-h-11">Lanjutkan mengedit</flux:button><flux:button type="button" wire:click="discardProductChanges" variant="danger" class="min-h-11">Buang perubahan</flux:button></div>
                            </div>
                        @endif
                        @if($categories->isEmpty())
                            <div class="rounded-xl border border-pos-border bg-pos-soft p-4 text-sm"><p>Belum ada kategori. Tambahkan kategori terlebih dahulu untuk menyimpan produk.</p><a href="{{ route('menu.category') }}" class="mt-2 inline-flex min-h-11 items-center font-semibold text-pos-link underline">Kelola kategori</a></div>
                        @endif
                        <flux:input wire:model="productName" label="Nama produk" maxlength="255" autofocus />
                        <div class="grid gap-5 sm:grid-cols-2">
                            <flux:select wire:model.live="productCategoryId" label="Kategori">
                                <option value="">Pilih kategori...</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" wire:key="product-category-{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="productPrice" type="number" min="0" step="0.01" label="Harga ({{ config('pos.currency') }})" />
                        </div>
                        <div class="space-y-3 border-t border-pos-border pt-5" wire:key="product-image-{{ $productId ?? 'new' }}-{{ (int) $showProductModal }}">
                            <flux:input type="file" wire:model="productImage" label="Gambar produk (opsional)" accept="image/*" description="Pilih gambar dengan ukuran maksimal 2 MB. Gambar saat ini tetap digunakan jika tidak diganti." />
                            <p data-product-upload-status wire:loading wire:target="productImage" role="status" class="text-sm text-pos-muted">Mengunggah gambar...</p>
                            @if($productImage && ! $errors->has('productImage'))
                                <div class="space-y-2"><p class="text-sm text-pos-muted">Pratinjau:</p><img src="{{ $productImage->temporaryUrl() }}" alt="Pratinjau gambar produk" class="size-24 rounded-xl border border-pos-border object-cover"></div>
                            @elseif($productImageUrl)
                                <div class="space-y-2"><p class="text-sm text-pos-muted">Gambar saat ini:</p><img src="{{ Storage::disk('public')->url($productImageUrl) }}" alt="{{ $productName }}" class="size-24 rounded-xl border border-pos-border object-cover"></div>
                            @endif
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-pos-border bg-pos-soft px-5 py-5 sm:px-6">
                        <flux:button type="button" wire:click="requestProductClose" wire:loading.attr="disabled" wire:target="productImage,saveProduct" class="min-h-11">Batal</flux:button>
                        <flux:button wire:key="product-save-{{ $showProductDiscardConfirmation || $categories->isEmpty() ? 'blocked' : 'allowed' }}" type="submit" variant="primary" :disabled="$showProductDiscardConfirmation || $categories->isEmpty()" wire:loading.attr="disabled" wire:target="productImage,saveProduct" class="min-h-11"><span wire:loading.remove wire:target="saveProduct">{{ $productId ? 'Simpan perubahan' : 'Tambah produk' }}</span><span wire:loading wire:target="saveProduct">Menyimpan...</span></flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="product-delete" data-product-delete wire:model.self="showProductDeleteModal" :closable="false" :dismissible="false" :escapable="false" class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-lg overflow-y-auto rounded-2xl border border-pos-border bg-pos-surface! text-pos-ink dark:bg-pos-surface!">
                <div class="space-y-5">
                    <flux:heading id="product-delete-heading" size="lg" level="2" class="text-pos-ink">Hapus produk?</flux:heading>
                    <p id="product-delete-description" class="break-words font-semibold">{{ $deletingProduct?->name ?? 'Produk sudah dihapus' }}</p>
                    @error('productDelete')<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-sm text-pos-danger">{{ $message }}</div>@enderror
                    <p class="text-sm text-pos-muted">{{ $deletingProduct ? 'Produk ini akan dihapus dari menu dan tidak dapat dipilih untuk pesanan baru. Pesanan yang sudah dibuat tetap tersimpan.' : 'Produk tidak tersedia. Tutup dialog dan muat ulang halaman melalui browser.' }}</p>
                    <div class="flex flex-wrap justify-end gap-3">
                        <flux:button autofocus type="button" wire:click="closeProductDeleteModal" wire:loading.attr="disabled" wire:target="deleteProduct" class="min-h-11">Batal</flux:button>
                        <flux:button wire:key="product-delete-{{ $deletingProduct ? 'allowed' : 'blocked' }}" type="button" wire:click="deleteProduct" variant="danger" :disabled="! $deletingProduct" wire:loading.attr="disabled" wire:target="deleteProduct" class="min-h-11"><span wire:loading.remove wire:target="deleteProduct">Hapus produk</span><span wire:loading wire:target="deleteProduct">Menghapus...</span></flux:button>
                    </div>
                </div>
            </flux:modal>
        @elseif($section === 'ingredients')
            <section class="min-w-0 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm sm:p-8" aria-labelledby="ingredients-heading">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h1 id="ingredients-heading" tabindex="-1" class="text-3xl font-extrabold tracking-tight">Kelola Bahan</h1>
                        <p class="mt-2 text-pos-muted">Kelola bahan resep dan pantau stok yang dapat digunakan.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <flux:button data-ingredient-open="create" wire:click="createIngredient" variant="primary" icon="plus">Tambah bahan</flux:button>
                    </div>
                </div>
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.300ms="ingredientSearch" label="Cari bahan" placeholder="Cari nama bahan..." icon="magnifying-glass" />
                    <flux:select wire:model.live="ingredientStatusFilter" label="Status stok">
                        <option value="">Semua status</option>
                        <option value="empty">Habis</option>
                        <option value="low">Rendah</option>
                        <option value="sufficient">Cukup</option>
                    </flux:select>
                </div>
                <p wire:loading.delay wire:target="ingredientSearch,ingredientStatusFilter,resetIngredientFilters,refreshIngredients,nextPage,previousPage" role="status" class="mt-3 text-sm text-pos-link">Memuat bahan...</p>

                @if($ingredientRows->isEmpty())
                    <div class="py-12 text-center">
                        <p class="font-semibold">{{ $hasIngredients ? 'Tidak ada bahan yang cocok dengan filter.' : 'Belum ada bahan.' }}</p>
                        <p class="mt-2 text-sm text-pos-muted">{{ $hasIngredients ? 'Coba nama lain atau tampilkan semua status stok.' : 'Tambahkan bahan untuk menyusun resep dan mengelola stok.' }}</p>
                        <div class="mt-5">
                            @if($hasIngredients)
                                <flux:button wire:click="resetIngredientFilters">Reset filter</flux:button>
                            @else
                                <flux:button data-ingredient-open="create" wire:click="createIngredient" variant="primary" icon="plus">Tambah bahan pertama</flux:button>
                            @endif
                        </div>
                    </div>
                @else
                    <table class="mt-7 hidden w-full table-fixed text-left lg:table">
                        <thead class="border-b border-pos-border text-xs font-bold uppercase tracking-wider text-pos-muted">
                            <tr><th scope="col" class="w-[22%] pb-4">Bahan / satuan</th><th scope="col" class="w-[15%] pb-4">Stok total</th><th scope="col" class="w-[15%] pb-4">Dicadangkan</th><th scope="col" class="w-[15%] pb-4">Stok tersedia</th><th scope="col" class="w-[12%] pb-4">Status</th><th scope="col" class="pb-4 text-right">Aksi</th></tr>
                        </thead>
                        <tbody class="divide-y divide-pos-border">
                            @foreach($ingredientRows as $ingredient)
                                @php
                                    $overview = $ingredientOverview[$ingredient->id];
                                @endphp
                                <tr wire:key="ingredient-table-{{ $ingredient->id }}" class="hover:bg-pos-soft/50">
                                    <td class="py-5 pr-4"><p class="break-words font-semibold">{{ $ingredient->name }}</p><p class="mt-1 break-words text-sm text-pos-muted">{{ $ingredient->unit }}</p></td>
                                    <td class="break-words py-5 pr-3 text-sm tabular-nums">{{ number_format((float) $ingredient->current_stock, 2, ',', '.') }} {{ $ingredient->unit }}</td>
                                    <td class="break-words py-5 pr-3 text-sm text-pos-muted tabular-nums">{{ number_format((float) $ingredient->reserved_stock, 2, ',', '.') }} {{ $ingredient->unit }}</td>
                                    <td class="break-words py-5 pr-3 text-sm font-semibold tabular-nums">{{ $overview['available'] }} {{ $ingredient->unit }}</td>
                                    <td class="py-5 pr-3"><span class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold {{ $overview['status'] === 'Habis' ? 'border-pos-danger-border bg-pos-danger-soft text-pos-danger' : 'border-pos-border bg-pos-soft text-pos-ink' }}">@if($overview['status'] === 'Rendah')<flux:icon.exclamation-triangle variant="mini" class="size-3.5 shrink-0" />@endif{{ $overview['status'] }}</span></td>
                                    <td class="py-5 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <flux:button data-ingredient-open="edit-{{ $ingredient->id }}" wire:click="editIngredient({{ $ingredient->id }})" size="sm" aria-label="Ubah bahan {{ $ingredient->name }}">Ubah</flux:button>
                                            <flux:button data-ingredient-open="delete-{{ $ingredient->id }}" wire:click="requestIngredientDelete({{ $ingredient->id }})" size="sm" variant="ghost" class="text-pos-danger!" aria-label="Hapus bahan {{ $ingredient->name }}">Hapus</flux:button>
                                        </div>
                                        @if($ingredient->recipes_count > 0 || (float) $ingredient->reserved_stock > 0)
                                            <p class="mt-2 text-xs text-pos-muted">Belum dapat dihapus: {{ $ingredient->recipes_count > 0 ? 'dipakai dalam resep' : 'stok dicadangkan' }}.</p>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:hidden">
                        @foreach($ingredientRows as $ingredient)
                            @php
                                $overview = $ingredientOverview[$ingredient->id];
                            @endphp
                            <article wire:key="ingredient-card-{{ $ingredient->id }}" class="min-w-0 rounded-xl border border-pos-border p-4">
                                <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h2 class="break-words font-semibold">{{ $ingredient->name }}</h2><p class="mt-1 break-words text-sm text-pos-muted">{{ $ingredient->unit }}</p></div><span class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold {{ $overview['status'] === 'Habis' ? 'border-pos-danger-border bg-pos-danger-soft text-pos-danger' : 'border-pos-border bg-pos-soft text-pos-ink' }}">@if($overview['status'] === 'Rendah')<flux:icon.exclamation-triangle variant="mini" class="size-3.5 shrink-0" />@endif{{ $overview['status'] }}</span></div>
                                <dl class="mt-4 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                                    <dt class="text-pos-muted">Stok total</dt><dd class="break-words text-right tabular-nums">{{ number_format((float) $ingredient->current_stock, 2, ',', '.') }} {{ $ingredient->unit }}</dd>
                                    <dt class="text-pos-muted">Dicadangkan</dt><dd class="break-words text-right tabular-nums">{{ number_format((float) $ingredient->reserved_stock, 2, ',', '.') }} {{ $ingredient->unit }}</dd>
                                    <dt class="font-semibold">Stok tersedia</dt><dd class="break-words text-right font-semibold tabular-nums">{{ $overview['available'] }} {{ $ingredient->unit }}</dd>
                                </dl>
                                @if($ingredient->recipes_count > 0 || (float) $ingredient->reserved_stock > 0)<p class="mt-4 text-xs text-pos-muted">Belum dapat dihapus: {{ $ingredient->recipes_count > 0 ? 'dipakai dalam resep' : 'stok dicadangkan' }}.</p>@endif
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <flux:button data-ingredient-open="edit-{{ $ingredient->id }}" wire:click="editIngredient({{ $ingredient->id }})" aria-label="Ubah bahan {{ $ingredient->name }}">Ubah</flux:button>
                                    <flux:button data-ingredient-open="delete-{{ $ingredient->id }}" wire:click="requestIngredientDelete({{ $ingredient->id }})" variant="ghost" class="text-pos-danger!" aria-label="Hapus bahan {{ $ingredient->name }}">Hapus</flux:button>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
                <div class="mt-6 flex flex-col gap-3 border-t border-pos-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p role="status" class="text-sm text-pos-muted">Menampilkan {{ $ingredientRows->firstItem() ?? 0 }} sampai {{ $ingredientRows->lastItem() ?? 0 }} dari {{ $ingredientRows->total() }} bahan</p>
                    <div class="flex gap-2">
                        <flux:button wire:click="previousPage('ingredientsPage')" :disabled="$ingredientRows->onFirstPage()">Sebelumnya</flux:button>
                        <flux:button wire:click="nextPage('ingredientsPage')" :disabled="! $ingredientRows->hasMorePages()">Berikutnya</flux:button>
                    </div>
                </div>
            </section>

            <flux:modal name="ingredient-editor" data-ingredient-editor wire:model.self="showIngredientModal" :closable="false" :dismissible="false" :escapable="false" class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-2xl overflow-hidden rounded-2xl border border-pos-border bg-pos-surface! !p-0 text-pos-ink dark:bg-pos-surface!">
                <form wire:submit="saveIngredient" class="flex max-h-[calc(100dvh-2rem)] flex-col">
                    <div class="flex shrink-0 items-start justify-between gap-3 border-b border-pos-border px-5 py-5 sm:px-6">
                        <div><flux:heading id="ingredient-editor-heading" size="xl" level="2" class="text-pos-ink">{{ $ingredientId ? 'Ubah bahan' : 'Tambah bahan' }}</flux:heading><p id="ingredient-editor-description" class="mt-1 text-sm text-pos-muted">Isi identitas bahan dan jumlah stok total dalam satuan yang sama.</p></div>
                        <flux:button type="button" wire:click="requestIngredientClose" wire:loading.attr="disabled" wire:target="saveIngredient" variant="ghost" icon="x-mark" square aria-label="Tutup editor bahan" />
                    </div>
                    <div class="min-h-0 space-y-5 overflow-y-auto p-5 sm:p-6">
                        @error('ingredientForm')<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-sm text-pos-danger">{{ $message }}</div>@enderror
                        @if($showIngredientDiscardConfirmation)
                            <div data-ingredient-confirmation role="alert" class="space-y-3 rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4">
                                <p class="font-semibold">Buang perubahan yang belum disimpan?</p><p class="text-sm">Perubahan di editor belum diterapkan ke bahan atau stok.</p>
                                <div class="flex flex-wrap gap-2"><flux:button type="button" wire:click="continueIngredientEditing">Lanjutkan mengedit</flux:button><flux:button type="button" wire:click="discardIngredientChanges" variant="danger">Buang perubahan</flux:button></div>
                            </div>
                        @endif
                        <flux:input wire:model="ingredientName" label="Nama bahan" maxlength="255" autofocus />
                        <flux:input wire:model="ingredientUnit" label="Satuan" placeholder="Misalnya g, ml, atau pcs" maxlength="50" description="Gunakan satuan yang sama dengan kebutuhan bahan pada resep. Satuan tidak dikonversi otomatis." />
                        @if($editingIngredient)
                            <div class="rounded-xl border border-pos-border bg-pos-soft p-4 text-sm">
                                <p class="font-semibold">Stok tersimpan saat ini</p>
                                <dl class="mt-2 grid grid-cols-2 gap-2"><dt class="text-pos-muted">Dicadangkan</dt><dd class="break-words text-right tabular-nums">{{ number_format((float) $editingIngredient->reserved_stock, 2, ',', '.') }} {{ $editingIngredient->unit }}</dd><dt class="text-pos-muted">Tersedia</dt><dd class="break-words text-right tabular-nums">{{ number_format(max(0, \App\Domain\Catalog\ProductAvailability::hundredths($editingIngredient->current_stock) - \App\Domain\Catalog\ProductAvailability::hundredths($editingIngredient->reserved_stock)) / 100, 2, ',', '.') }} {{ $editingIngredient->unit }}</dd></dl>
                            </div>
                        @endif
                        <div class="grid gap-5 sm:grid-cols-2">
                            <flux:input wire:model="ingredientStock" type="number" min="0" max="99999999.99" step="0.01" label="Stok total" description="Termasuk stok dicadangkan. Tidak boleh lebih kecil dari jumlah yang dicadangkan." />
                            <flux:input wire:model="ingredientThreshold" type="number" min="0" max="99999999.99" step="0.01" label="Batas stok rendah" description="Penanda visual untuk stok tersedia; tidak memicu notifikasi atau membatasi penjualan." />
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-pos-border bg-pos-soft px-5 py-5 sm:px-6">
                        <flux:button type="button" wire:click="requestIngredientClose" wire:loading.attr="disabled" wire:target="saveIngredient">Batal</flux:button>
                        <flux:button wire:key="ingredient-save-{{ $showIngredientDiscardConfirmation ? 'confirming' : 'editing' }}" type="submit" variant="primary" :disabled="$showIngredientDiscardConfirmation" wire:loading.attr="disabled" wire:target="saveIngredient"><span wire:loading.remove wire:target="saveIngredient">{{ $ingredientId ? 'Simpan perubahan' : 'Tambah bahan' }}</span><span wire:loading wire:target="saveIngredient">Menyimpan...</span></flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="ingredient-delete" data-ingredient-delete wire:model.self="showIngredientDeleteModal" :closable="false" :dismissible="false" :escapable="false" class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-lg overflow-y-auto rounded-2xl border border-pos-border bg-pos-surface! text-pos-ink dark:bg-pos-surface!">
                <div class="space-y-5">
                    <flux:heading id="ingredient-delete-heading" size="lg" level="2" class="text-pos-ink">Hapus bahan?</flux:heading>
                    <p id="ingredient-delete-description" class="break-words text-sm">{{ $deletingIngredient?->name ?? 'Bahan sudah dihapus' }}</p>
                    @error('ingredientDelete')<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-sm text-pos-danger">{{ $message }}</div>@enderror
                    @if(! $deletingIngredient)
                        <p class="text-sm text-pos-muted">Bahan tidak tersedia. Tutup dialog dan muat ulang halaman melalui browser.</p>
                    @elseif($deletingIngredient->recipes_count > 0 || (float) $deletingIngredient->reserved_stock > 0)
                        <div class="space-y-2 rounded-xl border border-pos-border bg-pos-soft p-4 text-sm">
                            <p class="font-semibold">Bahan belum dapat dihapus.</p>
                            @if($deletingIngredient->recipes_count > 0)<p>Dipakai dalam {{ $deletingIngredient->recipes_count }} resep. Hapus bahan dari resep terlebih dahulu.</p>@endif
                            @if((float) $deletingIngredient->reserved_stock > 0)<p>Masih ada {{ number_format((float) $deletingIngredient->reserved_stock, 2, ',', '.') }} {{ $deletingIngredient->unit }} yang dicadangkan untuk pesanan.</p>@endif
                        </div>
                    @else
                        <p class="text-sm text-pos-muted">Bahan ini akan dihapus dari daftar bahan.</p>
                    @endif
                    <div class="flex flex-wrap justify-end gap-3">
                        <flux:button autofocus type="button" wire:click="closeIngredientDeleteModal" wire:loading.attr="disabled" wire:target="deleteIngredient">Batal</flux:button>
                        <flux:button wire:key="ingredient-delete-{{ $deletingIngredient && $deletingIngredient->recipes_count === 0 && (float) $deletingIngredient->reserved_stock === 0.0 ? 'allowed' : 'blocked' }}" type="button" wire:click="deleteIngredient" variant="danger" :disabled="! $deletingIngredient || $deletingIngredient->recipes_count > 0 || (float) $deletingIngredient->reserved_stock > 0" wire:loading.attr="disabled" wire:target="deleteIngredient"><span wire:loading.remove wire:target="deleteIngredient">Hapus bahan</span><span wire:loading wire:target="deleteIngredient">Menghapus...</span></flux:button>
                    </div>
                </div>
            </flux:modal>
        @elseif($section === 'recipes')
            <section data-recipe-page class="min-w-0 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm sm:p-8" aria-labelledby="recipes-heading">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 id="recipes-heading" tabindex="-1" class="text-3xl font-extrabold tracking-tight">Kelola Resep</h1>
                        <p class="mt-2 text-pos-muted">Atur kebutuhan bahan untuk satu porsi produk.</p>
                    </div>
                </div>
                <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <flux:input wire:model.live.debounce.300ms="recipeSearch" label="Cari produk" placeholder="Cari nama produk..." icon="magnifying-glass" />
                    <flux:select wire:model.live="recipeCategoryFilter" label="Kategori">
                        <option value="">Semua kategori</option>
                        @foreach($categories as $category)
                            <option wire:key="recipe-category-{{ $category->id }}" value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="recipePresenceFilter" label="Kelengkapan resep">
                        <option value="">Semua produk</option>
                        <option value="with">Dengan resep</option>
                        <option value="without">Tanpa resep</option>
                    </flux:select>
                </div>
                <p wire:loading.delay wire:target="recipeSearch,recipeCategoryFilter,recipePresenceFilter,refreshRecipes,nextPage,previousPage" role="status" class="mt-3 text-sm text-pos-link">Memuat produk...</p>

                @if($recipeProducts->isEmpty())
                    <div class="py-12 text-center">
                        <p class="font-semibold">{{ $hasRecipeProducts ? 'Tidak ada produk yang cocok dengan filter.' : 'Belum ada produk.' }}</p>
                        <p class="mt-2 text-sm text-pos-muted">{{ $hasRecipeProducts ? 'Coba nama lain atau tampilkan semua produk.' : 'Tambahkan produk terlebih dahulu, lalu atur bahan untuk resepnya.' }}</p>
                        <div class="mt-5">
                            @if($hasRecipeProducts)
                                <flux:button wire:click="resetRecipeFilters">Reset filter</flux:button>
                            @else
                                <flux:button :href="route('menu.menu')" variant="primary">Kelola produk</flux:button>
                            @endif
                        </div>
                    </div>
                @else
                    <table class="mt-7 hidden w-full table-fixed text-left md:table">
                        <thead class="border-b border-pos-border text-xs font-bold uppercase tracking-wider text-pos-muted">
                            <tr><th scope="col" class="w-2/5 pb-4">Produk</th><th scope="col" class="pb-4">Bahan</th><th scope="col" class="w-1/5 pb-4">Status resep</th><th scope="col" class="pb-4">Porsi tersedia</th><th scope="col" class="w-36 pb-4 text-right">Aksi</th></tr>
                        </thead>
                        <tbody class="divide-y divide-pos-border">
                            @foreach($recipeProducts as $product)
                                <tr wire:key="recipe-product-table-{{ $product->id }}">
                                    <td class="py-5 pr-4">
                                        <div class="flex items-center gap-3">
                                            @if($product->image_url)
                                                <img src="{{ Storage::disk('public')->url($product->image_url) }}" alt="" loading="lazy" class="size-12 shrink-0 rounded-xl border border-pos-border object-cover">
                                            @else
                                                <span aria-label="Tanpa gambar" class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-pos-border bg-pos-soft"><flux:icon.photo class="size-5 text-pos-muted" /></span>
                                            @endif
                                            <div class="min-w-0"><p class="break-words font-semibold">{{ $product->name }}</p><p class="mt-1 break-words text-sm text-pos-muted">{{ $product->category?->name ?? 'Tanpa kategori' }}</p></div>
                                        </div>
                                    </td>
                                    <td class="py-5 text-sm">{{ $product->recipes->count() }} bahan</td>
                                    <td class="py-5 pr-3"><span class="inline-flex rounded-lg border border-pos-border bg-pos-soft px-2.5 py-1 text-xs font-semibold text-pos-ink">{{ $recipeOverview[$product->id]['status'] }}</span></td>
                                    <td class="py-5 pr-3"><span class="block break-all font-semibold tabular-nums">{{ number_format($recipeOverview[$product->id]['portions'], 0, ',', '.') }}</span><span class="block text-xs text-pos-muted">porsi</span></td>
                                    <td class="py-5 text-right"><flux:button data-recipe-open="{{ $product->id }}" wire:click="editProductRecipe({{ $product->id }})" size="sm" aria-label="Kelola resep {{ $product->name }}">{{ $product->recipes->isEmpty() ? 'Tambah resep' : 'Ubah resep' }}</flux:button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="mt-6 space-y-3 md:hidden">
                        @foreach($recipeProducts as $product)
                            <article wire:key="recipe-product-card-{{ $product->id }}" class="rounded-xl border border-pos-border p-4">
                                <div class="flex items-center gap-3">
                                    @if($product->image_url)
                                        <img src="{{ Storage::disk('public')->url($product->image_url) }}" alt="" loading="lazy" class="size-12 shrink-0 rounded-xl object-cover">
                                    @else
                                        <span aria-label="Tanpa gambar" class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-pos-soft"><flux:icon.photo class="size-5 text-pos-muted" /></span>
                                    @endif
                                    <div class="min-w-0"><h2 class="break-words font-semibold">{{ $product->name }}</h2><p class="mt-1 break-words text-sm text-pos-muted">{{ $product->category?->name ?? 'Tanpa kategori' }}</p></div>
                                </div>
                                <p class="mt-4 text-sm font-semibold">{{ $recipeOverview[$product->id]['status'] }}</p>
                                <div class="mt-2 flex flex-wrap justify-between gap-2 text-sm text-pos-muted"><span>{{ $product->recipes->count() }} bahan</span><span>{{ number_format($recipeOverview[$product->id]['portions'], 0, ',', '.') }} porsi tersedia</span></div>
                                <flux:button data-recipe-open="{{ $product->id }}" wire:click="editProductRecipe({{ $product->id }})" aria-label="Kelola resep {{ $product->name }}" class="mt-4 w-full">{{ $product->recipes->isEmpty() ? 'Tambah resep' : 'Ubah resep' }}</flux:button>
                            </article>
                        @endforeach
                    </div>
                @endif
                <div class="mt-6 flex flex-col gap-3 border-t border-pos-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p role="status" class="text-sm text-pos-muted">Menampilkan {{ $recipeProducts->firstItem() ?? 0 }} sampai {{ $recipeProducts->lastItem() ?? 0 }} dari {{ $recipeProducts->total() }} produk</p>
                    <div class="flex gap-2">
                        <flux:button wire:click="previousPage('recipesPage')" :disabled="$recipeProducts->onFirstPage()">Sebelumnya</flux:button>
                        <flux:button wire:click="nextPage('recipesPage')" :disabled="! $recipeProducts->hasMorePages()">Berikutnya</flux:button>
                    </div>
                </div>
            </section>

            <flux:modal name="recipe-editor" data-recipe-editor wire:model.self="showRecipeModal" :closable="false" :dismissible="false" :escapable="false" class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-3xl overflow-hidden rounded-2xl border border-pos-border bg-pos-surface! !p-0 text-pos-ink dark:bg-pos-surface!">
                <form wire:submit="saveWholeRecipe" class="flex max-h-[calc(100dvh-2rem)] flex-col">
                    <div class="flex shrink-0 items-start justify-between gap-3 border-b border-pos-border px-5 py-5 sm:px-6">
                        <div class="min-w-0"><flux:heading id="recipe-editor-heading" tabindex="-1" autofocus size="xl" level="2" class="break-words text-pos-ink">Resep {{ $editingRecipeProduct?->name ?? 'produk' }}</flux:heading><p id="recipe-editor-description" class="mt-1 break-words text-sm text-pos-muted">{{ $editingRecipeProduct?->category?->name ?? 'Tanpa kategori' }} · Kebutuhan bahan untuk satu porsi</p></div>
                        <flux:button type="button" wire:click="requestRecipeClose" wire:loading.attr="disabled" wire:target="saveWholeRecipe" variant="ghost" icon="x-mark" square aria-label="Tutup editor resep" />
                    </div>
                    <div class="min-h-0 space-y-5 overflow-y-auto p-5 sm:p-6">
                        @error('recipeForm')<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-sm text-pos-danger">{{ $message }}</div>@enderror
                        @if($recipeConflict)
                            <flux:button type="button" wire:click="requestRecipeReload" icon="arrow-path">Muat ulang resep</flux:button>
                        @endif
                        @if($showRecipeDiscardConfirmation)
                            <div data-recipe-confirmation role="alert" class="space-y-3 rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4">
                                <p class="font-semibold">{{ $recipeDiscardAction === 'reload' ? 'Muat ulang dan buang perubahan?' : 'Buang perubahan yang belum disimpan?' }}</p>
                                <p class="text-sm">{{ $recipeDiscardAction === 'reload' ? 'Resep terbaru akan menggantikan perubahan di editor.' : 'Perubahan di editor belum diterapkan ke resep produk.' }}</p>
                                <div class="flex flex-wrap gap-2"><flux:button type="button" wire:click="continueRecipeEditing">Lanjutkan mengedit</flux:button><flux:button type="button" wire:click="discardRecipeChanges" variant="danger">{{ $recipeDiscardAction === 'reload' ? 'Muat ulang dan buang' : 'Buang perubahan' }}</flux:button></div>
                            </div>
                        @endif
                        @if($showRecipeClearConfirmation)
                            <div data-recipe-confirmation role="alert" class="space-y-3 rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4">
                                <p class="font-semibold">Hapus seluruh bahan resep?</p><p class="text-sm">Produk tidak tersedia untuk pesanan baru sampai resep ditambahkan kembali. Pesanan yang sudah dibuat tetap menggunakan resep sebelumnya.</p>
                                <div class="flex flex-wrap gap-2"><flux:button type="button" wire:click="continueRecipeEditing">Lanjutkan mengedit</flux:button><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="saveWholeRecipe">Konfirmasi hapus resep</flux:button></div>
                            </div>
                        @endif
                        @if($ingredients->isEmpty())
                            <div class="rounded-xl border border-pos-border bg-pos-soft p-4 text-sm"><p>Belum ada bahan. Tambahkan bahan terlebih dahulu untuk menyusun resep.</p><a href="{{ route('menu.ingredient') }}" class="mt-2 inline-block font-semibold text-pos-link underline">Kelola bahan</a></div>
                        @endif
                        @foreach($recipeRows as $key => $row)
                            @php
                                $selectedIds = collect($recipeRows)->except($key)->pluck('inventory_item_id')->map(fn ($id) => (string) $id)->all();
                                $selectedIngredient = $ingredients->firstWhere('id', $row['inventory_item_id']);
                                $ingredientSearch = trim($recipeIngredientSearch[$key] ?? '');
                                $options = $ingredients->filter(fn ($ingredient) => (string) $ingredient->id === (string) $row['inventory_item_id'] || (! in_array((string) $ingredient->id, $selectedIds, true) && ($ingredientSearch === '' || Str::contains(Str::lower($ingredient->name), Str::lower($ingredientSearch)))));
                            @endphp
                            <fieldset data-recipe-row="{{ $key }}" wire:key="recipe-editor-row-{{ $key }}" class="min-w-0 rounded-xl border border-pos-border p-4">
                                <legend class="px-1 text-sm font-semibold">Bahan {{ $loop->iteration }}</legend>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="space-y-3">
                                        <flux:input wire:model.live.debounce.300ms="recipeIngredientSearch.{{ $key }}" label="Cari bahan {{ $loop->iteration }}" placeholder="Ketik nama bahan..." />
                                        <flux:select wire:model.live="recipeRows.{{ $key }}.inventory_item_id" label="Bahan {{ $loop->iteration }}">
                                            <option value="">Pilih bahan...</option>
                                            @if($row['inventory_item_id'] !== '' && ! $selectedIngredient)
                                                <option value="{{ $row['inventory_item_id'] }}">Bahan tidak tersedia — pilih pengganti</option>
                                            @endif
                                            @foreach($options as $ingredient)
                                                <option wire:key="recipe-option-{{ $key }}-{{ $ingredient->id }}" value="{{ $ingredient->id }}">{{ $ingredient->name }} ({{ $ingredient->unit }})</option>
                                            @endforeach
                                        </flux:select>
                                        @if($options->isEmpty() && $ingredients->isNotEmpty())<p class="text-xs text-pos-muted">Tidak ada bahan yang cocok atau semua bahan sudah dipilih.</p>@endif
                                    </div>
                                    <div class="space-y-3">
                                        <flux:input wire:model="recipeRows.{{ $key }}.quantity_required" type="number" min="0.01" max="999999.99" step="0.01" label="Jumlah per porsi {{ $loop->iteration }}" />
                                        <p class="text-sm text-pos-muted">Satuan: <span class="font-semibold text-pos-ink">{{ $selectedIngredient?->unit ?? 'Pilih bahan terlebih dahulu' }}</span></p>
                                        <flux:button type="button" wire:click="removeRecipeRow('{{ $key }}')" variant="ghost" icon="trash" size="sm" aria-label="Hapus bahan {{ $loop->iteration }} dari draft">Hapus bahan</flux:button>
                                    </div>
                                </div>
                            </fieldset>
                        @endforeach
                        @if($recipeRows === [])<p class="rounded-xl border border-dashed border-pos-border-strong p-5 text-center text-sm text-pos-muted">Belum ada bahan dalam resep ini. Tambahkan bahan untuk mulai menyusun resep.</p>@endif
                        <flux:button type="button" wire:click="addRecipeRow" :disabled="$ingredients->isEmpty()" icon="plus">Tambah bahan</flux:button>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-pos-border bg-pos-soft px-5 py-5 sm:px-6">
                        <span wire:loading wire:target="saveWholeRecipe" role="status" class="mr-auto text-sm text-pos-muted">Menyimpan resep...</span>
                        <flux:button type="button" wire:click="requestRecipeClose" wire:loading.attr="disabled" wire:target="saveWholeRecipe">Batal</flux:button>
                        <flux:button wire:key="recipe-save-{{ (int) $recipeConflict }}-{{ (int) $showRecipeDiscardConfirmation }}-{{ (int) $showRecipeClearConfirmation }}" type="submit" variant="primary" :disabled="$recipeConflict || $showRecipeDiscardConfirmation || $showRecipeClearConfirmation" wire:loading.attr="disabled" wire:target="saveWholeRecipe">Simpan resep</flux:button>
                    </div>
                </form>
            </flux:modal>
        @else
            <section data-discount-page class="min-w-0 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm sm:p-8" aria-labelledby="discounts-heading">
                <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 id="discounts-heading" tabindex="-1" class="text-3xl font-extrabold tracking-tight">Kelola Diskon</h1>
                        <p class="mt-2 text-pos-muted">Atur penawaran promo, rentang tanggal, dan jadwal mingguan.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <flux:button data-discount-open="create" wire:click="createDiscount" variant="primary" icon="plus">Tambah diskon</flux:button>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <flux:input wire:model.live.debounce.300ms="discountSearch" label="Cari diskon" placeholder="Cari nama promo..." icon="magnifying-glass" />
                    <flux:select wire:model.live="discountStatusFilter" label="Status diskon">
                        <option value="">Semua status</option><option value="active">Aktif</option><option value="inactive">Tidak aktif</option>
                    </flux:select>
                    <flux:select wire:model.live="discountScheduleFilter" label="Jadwal diskon">
                        <option value="">Semua jadwal</option><option value="interval">Rentang tanggal</option><option value="routine">Rutin mingguan</option>
                    </flux:select>
                </div>
                <p wire:loading.delay wire:target="discountSearch,discountStatusFilter,discountScheduleFilter,refreshDiscounts,resetDiscountFilters,nextPage,previousPage,gotoPage" role="status" class="mt-3 text-sm text-pos-link">Memuat diskon...</p>
                @if($discountRows->isEmpty())
                    <div class="py-12 text-center">
                        <p class="font-semibold">{{ $hasDiscounts ? 'Tidak ada diskon yang cocok dengan filter.' : 'Belum ada diskon.' }}</p>
                        <p class="mt-2 text-sm text-pos-muted">{{ $hasDiscounts ? 'Coba nama lain atau tampilkan semua status dan jadwal.' : 'Tambahkan promo dan pilih produk yang mendapatkan potongan.' }}</p>
                        <div class="mt-5">
                            @if($hasDiscounts)<flux:button wire:click="resetDiscountFilters">Reset filter</flux:button>
                            @else<flux:button data-discount-open="create" wire:click="createDiscount" variant="primary" icon="plus">Tambah diskon</flux:button>@endif
                        </div>
                    </div>
                @else
                <div class="mt-6 hidden lg:block">
                    <table class="w-full table-fixed text-left">
                        <thead class="border-b border-pos-border text-xs font-bold uppercase tracking-wider text-pos-muted">
                            <tr>
                                <th scope="col" class="w-[24%] pb-5 pl-2 pr-4">Nama &amp; nilai</th>
                                <th scope="col" class="w-[24%] pb-5 pr-4">Jadwal</th>
                                <th scope="col" class="w-[10%] pb-5 pr-4">Produk</th>
                                <th scope="col" class="w-[13%] pb-5 pr-4">Status</th>
                                <th scope="col" class="w-[29%] pb-5 pr-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-pos-border">
                            @foreach($discountRows as $discount)
                                <tr wire:key="discount-{{ $discount->id }}" class="hover:bg-pos-soft/50">
                                    <td class="py-6 pl-2 pr-6">
                                        <p class="break-words font-bold">{{ $discount->name }}</p>
                                        <p class="mt-1 text-sm text-pos-muted">{{ $discountOverview[$discount->id]['value'] }} potongan</p>
                                    </td>
                                    <td class="py-6 pr-6">
                                        <span class="inline-flex rounded-lg border border-pos-border bg-pos-soft px-2.5 py-1 text-xs font-semibold text-pos-link">{{ $discount->schedule_type === 'interval' ? 'Rentang tanggal' : 'Rutin mingguan' }}</span>
                                        <p class="mt-1 text-sm">
                                            @if($discount->schedule_type === 'interval')
                                                {{ $discount->start_date?->translatedFormat('d M Y') ?? '—' }} – {{ $discount->end_date?->translatedFormat('d M Y') ?? '—' }}
                                            @else
                                                {{ ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][$discount->routine_day] ?? '—' }} ({{ $discount->start_time ? substr($discount->start_time, 0, 5) : '—' }} – {{ $discount->end_time ? substr($discount->end_time, 0, 5) : '—' }})
                                            @endif
                                        </p>
                                        @if(! $discountOverview[$discount->id]['complete'])<p class="mt-2 text-xs text-pos-danger">Jadwal perlu diperbaiki.</p>@endif
                                    </td>
                                    <td class="py-6 pr-4 text-sm text-pos-muted">{{ $discount->products_count }} produk @if($discount->products_count === 0)<p class="mt-1 text-xs text-pos-danger">Pilih produk.</p>@endif</td>
                                    <td class="py-6 pr-6">
                                        <span @class(['inline-flex rounded-full px-3 py-1 text-xs font-bold', 'bg-pos-soft text-pos-link' => $discount->is_active, 'bg-pos-danger-soft text-pos-danger' => ! $discount->is_active])>{{ $discount->is_active ? 'Aktif' : 'Tidak aktif' }}</span>
                                    </td>
                                    <td class="py-6 pr-2">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <flux:button wire:click="toggleDiscount({{ $discount->id }})" wire:loading.attr="disabled" class="min-h-11" aria-label="{{ $discount->is_active ? 'Nonaktifkan' : 'Aktifkan' }} diskon {{ $discount->name }}">{{ $discount->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</flux:button>
                                            <flux:button data-discount-open="edit-{{ $discount->id }}" wire:click="editDiscount({{ $discount->id }})" variant="ghost" class="min-h-11" aria-label="Ubah diskon {{ $discount->name }}">Ubah</flux:button>
                                            <flux:button data-discount-open="delete-{{ $discount->id }}" wire:click="deleteDiscount({{ $discount->id }})" variant="ghost" class="min-h-11 text-pos-danger" aria-label="Hapus diskon {{ $discount->name }}">Hapus</flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-6 grid gap-4 lg:hidden">
                    @foreach($discountRows as $discount)
                        <article wire:key="discount-card-{{ $discount->id }}" class="min-w-0 rounded-xl border border-pos-border p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0"><h2 class="break-words font-bold">{{ $discount->name }}</h2><p class="mt-1 text-sm text-pos-muted">{{ $discountOverview[$discount->id]['value'] }} potongan</p></div>
                                <span @class(['shrink-0 rounded-full px-3 py-1 text-xs font-semibold', 'bg-pos-soft text-pos-link' => $discount->is_active, 'bg-pos-danger-soft text-pos-danger' => ! $discount->is_active])>{{ $discount->is_active ? 'Aktif' : 'Tidak aktif' }}</span>
                            </div>
                            <dl class="mt-4 space-y-3 text-sm">
                                <div><dt class="text-pos-muted">{{ $discount->schedule_type === 'interval' ? 'Rentang tanggal' : 'Rutin mingguan' }}</dt><dd class="mt-1">{{ $discountOverview[$discount->id]['schedule'] }}</dd></div>
                                <div><dt class="text-pos-muted">Produk</dt><dd>{{ $discount->products_count }} produk</dd></div>
                            </dl>
                            @if(! $discountOverview[$discount->id]['complete'])<p class="mt-2 text-sm text-pos-danger">Jadwal perlu diperbaiki.</p>@endif
                            @if($discount->products_count === 0)<p class="mt-2 text-sm text-pos-danger">Pilih produk.</p>@endif
                            <div class="mt-4 flex flex-wrap gap-2 border-t border-pos-border pt-4">
                                <flux:button wire:click="toggleDiscount({{ $discount->id }})" wire:loading.attr="disabled" class="min-h-11" aria-label="{{ $discount->is_active ? 'Nonaktifkan' : 'Aktifkan' }} diskon {{ $discount->name }}">{{ $discount->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</flux:button>
                                <flux:button data-discount-open="edit-{{ $discount->id }}" wire:click="editDiscount({{ $discount->id }})" class="min-h-11" aria-label="Ubah diskon {{ $discount->name }}">Ubah</flux:button>
                                <flux:button data-discount-open="delete-{{ $discount->id }}" wire:click="deleteDiscount({{ $discount->id }})" variant="ghost" class="min-h-11 text-pos-danger" aria-label="Hapus diskon {{ $discount->name }}">Hapus</flux:button>
                            </div>
                        </article>
                    @endforeach
                </div>
                @endif
                <div class="mt-6 flex flex-col gap-3 border-t border-pos-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p role="status" class="text-sm text-pos-muted">Menampilkan {{ $discountRows->firstItem() ?? 0 }} sampai {{ $discountRows->lastItem() ?? 0 }} dari {{ $discountRows->total() }} diskon</p>
                    <nav aria-label="Halaman diskon" class="flex gap-2">
                        <flux:button wire:click="previousPage('discountsPage')" wire:loading.attr="disabled" :disabled="$discountRows->onFirstPage()" class="min-h-11">Sebelumnya</flux:button>
                        <flux:button wire:click="nextPage('discountsPage')" wire:loading.attr="disabled" :disabled="! $discountRows->hasMorePages()" class="min-h-11">Berikutnya</flux:button>
                    </nav>
                </div>
            </section>

            <flux:modal data-discount-editor name="discount-form" wire:model.self="showDiscountModal" :dismissible="false" :closable="false" class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-2xl overflow-hidden rounded-2xl border border-pos-border bg-pos-surface! !p-0 text-pos-ink backdrop:bg-black/40 backdrop:backdrop-blur-sm dark:bg-pos-surface!">
                <form wire:submit="saveDiscount" class="flex max-h-[calc(100dvh-2rem)] flex-col">
                    <div class="flex shrink-0 items-center justify-between gap-4 border-b border-pos-border px-6 py-5">
                        <div><flux:heading id="discount-editor-heading" size="xl" level="2" class="font-bold text-pos-ink">{{ $discountId ? 'Ubah diskon' : 'Tambah diskon baru' }}</flux:heading><p id="discount-editor-description" class="mt-1 text-sm text-pos-muted">Lengkapi promo, jadwal, dan produk. Kolom bertanda * wajib diisi.</p></div>
                        <flux:button type="button" wire:click="closeDiscountModal" wire:loading.attr="disabled" wire:target="saveDiscount" variant="ghost" icon="x-mark" square aria-label="Tutup formulir diskon" class="text-pos-muted" />
                    </div>
                    <div class="min-h-0 space-y-6 overflow-y-auto p-6">
                        <flux:error name="discountForm" />
                        @if($showDiscountDiscardConfirmation)
                            <div data-discount-confirmation role="alert" class="space-y-3 rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4">
                                <p class="font-semibold">Buang perubahan yang belum disimpan?</p><p class="text-sm">Perubahan pada promo, jadwal, dan pilihan produk akan hilang.</p>
                                <div class="flex flex-wrap gap-3"><flux:button type="button" wire:click="continueDiscountEditing">Lanjutkan mengedit</flux:button><flux:button type="button" wire:click="discardDiscountChanges" variant="danger">Buang perubahan</flux:button></div>
                            </div>
                        @endif
                        <fieldset class="min-w-0 space-y-5 disabled:opacity-60" @disabled($showDiscountDiscardConfirmation)>
                        <legend class="mb-3 font-semibold">Detail promo</legend>
                        <flux:input wire:model="discountName" label="Nama promo *" placeholder="Contoh: Promo Jumat" maxlength="255" autofocus />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:select wire:model.live="discountType" label="Jenis diskon *">
                                <option value="percentage">Persentase (%)</option>
                                <option value="fixed_amount">Nominal tetap (Rp)</option>
                            </flux:select>
                            <flux:input wire:model="discountValue" :label="$discountType === 'percentage' ? 'Persentase diskon (%) *' : 'Potongan harga (Rp) *'" type="number" min="0.01" :max="$discountType === 'percentage' ? '100' : '99999999.99'" step="0.01" :placeholder="$discountType === 'percentage' ? 'Contoh: 10' : 'Contoh: 5000'" />
                        </div>
                        <p class="text-sm text-pos-muted">Maksimal dua angka desimal. Jika beberapa promo berlaku, harga terendah digunakan; potongan tidak dijumlahkan. Harga tidak akan kurang dari Rp0.</p>
                        <flux:checkbox wire:model="discountActive" :disabled="$showDiscountDiscardConfirmation" label="Aktifkan diskon" description="Potongan berlaku hanya saat jadwal sesuai dan produk dipilih." />
                        <div class="space-y-5 border-t border-pos-border pt-6">
                            <flux:heading level="3">Jadwal promo</flux:heading>
                            <p class="text-sm text-pos-muted">Jadwal mengikuti zona waktu {{ config('app.timezone') }}. Rentang tanggal mencakup tanggal mulai dan selesai. Jadwal mingguan harus selesai pada hari yang sama.</p>
                            <flux:select wire:model.live="scheduleType" label="Jenis jadwal *">
                                <option value="interval">Rentang tanggal tertentu</option>
                                <option value="routine">Rutin mingguan (contoh: setiap Jumat)</option>
                            </flux:select>
                            @if($scheduleType === 'interval')
                                <div wire:key="discount-interval" class="grid gap-4 rounded-xl border border-pos-border bg-pos-soft p-4 sm:grid-cols-2">
                                    <flux:input wire:model="startDate" label="Tanggal mulai *" type="date" />
                                    <flux:input wire:model="endDate" label="Tanggal selesai *" type="date" />
                                </div>
                            @else
                                <div wire:key="discount-routine" class="grid gap-4 rounded-xl border border-pos-border bg-pos-soft p-4 sm:grid-cols-3">
                                    <flux:select wire:model="routineDay" label="Hari *">
                                        <option value="">Pilih hari...</option>
                                        @foreach(['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'] as $day => $name)
                                            <option value="{{ $day }}">{{ $name }}</option>
                                        @endforeach
                                    </flux:select>
                                    <flux:input wire:model="startTime" label="Jam mulai *" type="time" />
                                    <flux:input wire:model="endTime" label="Jam selesai *" type="time" />
                                </div>
                            @endif
                        </div>
                        <div class="space-y-3 border-t border-pos-border pt-6">
                            <flux:heading level="3">Terapkan ke produk *</flux:heading>
                            <p class="text-sm text-pos-muted">Pilih minimal satu produk. Pilihan tetap tersimpan saat hasil pencarian atau kategori berubah.</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <flux:input wire:model.live.debounce.300ms="discountProductSearch" label="Cari produk" placeholder="Cari nama produk..." />
                                <flux:select wire:model.live="discountProductCategoryFilter" label="Kategori produk"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</flux:select>
                            </div>
                            <p role="status" class="text-sm font-semibold">{{ count(array_unique($selectedProducts)) }} produk dipilih · {{ $discountProductResults->count() }} hasil</p>
                            <div class="flex flex-wrap gap-2">
                                <flux:button type="button" wire:click="selectDiscountProductResults" wire:loading.attr="disabled" :disabled="$showDiscountDiscardConfirmation || $discountProductResults->isEmpty()" class="min-h-11">Pilih semua hasil</flux:button>
                                <flux:button type="button" wire:click="clearDiscountProductResults" wire:loading.attr="disabled" :disabled="$showDiscountDiscardConfirmation || $discountProductResults->isEmpty()" class="min-h-11">Hapus pilihan hasil</flux:button>
                            </div>
                            <div class="grid max-h-64 gap-3 overflow-y-auto rounded-xl border border-pos-border bg-pos-soft p-4">
                                @forelse($discountProductResults as $product)
                                    <label wire:key="discount-product-{{ $product->id }}" class="flex cursor-pointer items-start gap-3 rounded-xl border border-pos-border bg-pos-surface p-3 shadow-sm hover:border-pos-border-strong">
                                        <flux:checkbox wire:model.live="selectedProducts" :disabled="$showDiscountDiscardConfirmation" value="{{ $product->id }}" aria-label="Pilih {{ $product->name }}" />
                                        <span class="min-w-0 flex-1"><span class="block break-words text-sm font-medium">{{ $product->name }}</span><span class="mt-1 block text-xs font-semibold text-pos-muted">Rp{{ number_format((float) $product->price, 0, ',', '.') }}</span></span>
                                    </label>
                                @empty
                                    <div class="text-sm text-pos-muted">@if($products->isEmpty())<p>Belum ada produk. Tambahkan produk sebelum menyimpan diskon.</p><a href="{{ route('menu.menu') }}" wire:navigate class="mt-2 inline-flex min-h-11 items-center font-semibold text-pos-link underline">Kelola produk</a>@else<p>Tidak ada produk yang cocok. Ubah pencarian atau kategori.</p>@endif</div>
                                @endforelse
                            </div>
                            <flux:error name="selectedProducts" />
                        </div>
                        </fieldset>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-pos-border bg-pos-soft px-6 py-5">
                        <span wire:loading wire:target="saveDiscount" role="status" class="mr-auto text-sm text-pos-muted">Menyimpan diskon...</span>
                        <flux:button type="button" wire:click="closeDiscountModal" wire:loading.attr="disabled" wire:target="saveDiscount" variant="ghost">Batal</flux:button>
                        <flux:button wire:key="discount-save-{{ (int) $showDiscountDiscardConfirmation }}-{{ (int) $products->isEmpty() }}" type="submit" variant="primary" :disabled="$showDiscountDiscardConfirmation || $products->isEmpty()" wire:loading.attr="disabled" wire:target="saveDiscount">Simpan diskon</flux:button>
                    </div>
                </form>
            </flux:modal>
            <flux:modal data-discount-delete name="discount-delete" wire:model.self="showDiscountDeleteModal" :dismissible="false" :closable="false" class="w-[calc(100vw-2rem)] max-w-lg rounded-2xl bg-pos-surface! text-pos-ink">
                <div class="space-y-5">
                    <flux:heading id="discount-delete-heading" size="xl">Hapus diskon?</flux:heading>
                    <p id="discount-delete-description" class="break-words">Diskon <strong>{{ $deletingDiscountName }}</strong> akan dihapus dari pilihan promo untuk pesanan baru. Harga pada pesanan yang sudah dibuat tetap tersimpan.</p>
                    <flux:error name="discountDelete" />
                    <p wire:loading wire:target="confirmDiscountDeletion" role="status" class="text-sm text-pos-muted">Menghapus diskon...</p>
                    <div class="flex flex-wrap justify-end gap-3">
                        <flux:button type="button" wire:click="closeDiscountDeleteModal" wire:loading.attr="disabled" wire:target="confirmDiscountDeletion">Batal</flux:button>
                        <flux:button wire:key="discount-delete-{{ $deletingDiscountId ?? 'none' }}-{{ (int) $showDiscountDeleteModal }}" type="button" wire:click="confirmDiscountDeletion" variant="danger" :disabled="! $showDiscountDeleteModal || $deletingDiscountId === null" wire:loading.attr="disabled" wire:target="confirmDiscountDeletion">Hapus diskon</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </main>
</div>
