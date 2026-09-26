<?php

use App\Domain\Catalog\ManageCatalog;
use App\Models\Category;
use App\Models\Discount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public string $section = 'products';
    public ?int $categoryId = null;
    public string $categoryName = '';
    public ?int $productId = null;
    public string $productName = '';
    public ?int $productCategoryId = null;
    public string $productPrice = '';
    public ?string $productImageUrl = null;
    public $productImage = null;
    public ?int $stockProductId = null;
    public int $productStock = 0;
    public int $productThreshold = 0;
    public ?int $ingredientId = null;
    public string $ingredientName = '';
    public string $ingredientUnit = '';
    public string $ingredientStock = '0';
    public string $ingredientThreshold = '0';
    public ?int $recipeProductId = null;
    public ?int $recipeIngredientId = null;
    public string $recipeQuantity = '1';
    public ?int $discountId = null;
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
            'menu.inventory' => 'inventory',
            'menu.ingredient' => 'ingredients',
            'menu.discount' => 'discounts',
            'menu.recipes' => 'recipes',
            default => 'products',
        };
    }

    public function saveCategory(ManageCatalog $catalog): void
    {
        $catalog->saveCategory($this->categoryId, $this->categoryName);
        $this->reset('categoryId', 'categoryName');
        session()->flash('message', 'Category saved.');
    }

    public function editCategory(int $id): void
    {
        $category = Category::findOrFail($id);
        $this->categoryId = $id;
        $this->categoryName = $category->name;
    }

    public function deleteCategory(int $id, ManageCatalog $catalog): void
    {
        $catalog->deleteCategory($id);
    }

    public function saveProduct(ManageCatalog $catalog): void
    {
        $this->validate(['productImage' => 'nullable|image|max:2048']);
        $imageUrl = $this->productImage ? $this->productImage->store('products', 'public') : $this->productImageUrl;
        $catalog->saveProduct($this->productId, [
            'name' => $this->productName,
            'category_id' => $this->productCategoryId,
            'price' => $this->productPrice,
            'image_url' => $imageUrl,
        ]);
        $this->reset('productId', 'productName', 'productCategoryId', 'productPrice', 'productImageUrl', 'productImage');
        session()->flash('message', 'Product saved.');
    }

    public function editProduct(int $id): void
    {
        $product = Product::findOrFail($id);
        $this->productId = $id;
        $this->productName = $product->name;
        $this->productCategoryId = $product->category_id;
        $this->productPrice = (string) $product->price;
        $this->productImageUrl = $product->image_url;
    }

    public function deleteProduct(int $id, ManageCatalog $catalog): void
    {
        $catalog->deleteProduct($id);
    }

    public function editStock(int $id): void
    {
        $product = Product::findOrFail($id);
        $this->stockProductId = $id;
        $this->productStock = $product->current_stock;
        $this->productThreshold = $product->low_stock_threshold;
    }

    public function saveStock(ManageCatalog $catalog): void
    {
        $catalog->setProductStock($this->stockProductId, $this->productStock, $this->productThreshold);
        $this->reset('stockProductId', 'productStock', 'productThreshold');
        session()->flash('message', 'Stock adjusted.');
    }

    public function saveIngredient(ManageCatalog $catalog): void
    {
        $catalog->saveIngredient($this->ingredientId, [
            'name' => $this->ingredientName,
            'unit' => $this->ingredientUnit,
            'current_stock' => $this->ingredientStock,
            'low_stock_threshold' => $this->ingredientThreshold,
        ]);
        $this->reset('ingredientId', 'ingredientName', 'ingredientUnit', 'ingredientStock', 'ingredientThreshold');
        session()->flash('message', 'Ingredient saved.');
    }

    public function editIngredient(int $id): void
    {
        $ingredient = InventoryItem::findOrFail($id);
        $this->ingredientId = $id;
        $this->ingredientName = $ingredient->name;
        $this->ingredientUnit = $ingredient->unit;
        $this->ingredientStock = (string) $ingredient->current_stock;
        $this->ingredientThreshold = (string) $ingredient->low_stock_threshold;
    }

    public function deleteIngredient(int $id, ManageCatalog $catalog): void
    {
        $catalog->deleteIngredient($id);
    }

    public function saveRecipe(ManageCatalog $catalog): void
    {
        $catalog->saveRecipe($this->recipeProductId, $this->recipeIngredientId, (float) $this->recipeQuantity);
        $this->reset('recipeProductId', 'recipeIngredientId', 'recipeQuantity');
        session()->flash('message', 'Recipe saved.');
    }

    public function deleteRecipe(int $productId, int $ingredientId, ManageCatalog $catalog): void
    {
        $catalog->deleteRecipe($productId, $ingredientId);
    }

    public function saveDiscount(ManageCatalog $catalog): void
    {
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
        ], array_map('intval', $this->selectedProducts));
        $this->reset('discountId', 'discountName', 'discountValue', 'startDate', 'endDate', 'routineDay', 'startTime', 'endTime', 'selectedProducts');
        session()->flash('message', 'Discount saved.');
    }

    public function editDiscount(int $id): void
    {
        $discount = Discount::with('products')->findOrFail($id);
        $this->discountId = $id;
        $this->discountName = $discount->name;
        $this->discountType = $discount->discount_type;
        $this->discountValue = (string) $discount->discount_value;
        $this->scheduleType = $discount->schedule_type;
        $this->startDate = $discount->start_date?->format('Y-m-d');
        $this->endDate = $discount->end_date?->format('Y-m-d');
        $this->routineDay = $discount->routine_day;
        $this->startTime = $discount->start_time;
        $this->endTime = $discount->end_time;
        $this->discountActive = $discount->is_active;
        $this->selectedProducts = $discount->products->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function toggleDiscount(int $id, ManageCatalog $catalog): void
    {
        $catalog->toggleDiscount($id);
    }

    public function deleteDiscount(int $id, ManageCatalog $catalog): void
    {
        $catalog->deleteDiscount($id);
    }

    public function with(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(),
            'products' => Product::with('category')->orderBy('name')->get(),
            'ingredients' => InventoryItem::orderBy('name')->get(),
            'recipes' => Recipe::with('product', 'inventoryItem')->orderBy('product_id')->get(),
            'discounts' => Discount::with('products')->orderBy('name')->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-slate-50 text-slate-900">
    <?php if (isset($component)) { $__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.pos-nav','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('pos-nav'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b)): ?>
<?php $attributes = $__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b; ?>
<?php unset($__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b)): ?>
<?php $component = $__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b; ?>
<?php unset($__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b); ?>
<?php endif; ?>
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-blue-700">Management</p><h1 class="mt-1 text-3xl font-bold">Catalog and inventory</h1></div>
        <nav class="flex flex-wrap gap-2 text-sm font-semibold">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = ['products' => 'menu.menu', 'categories' => 'menu.category', 'inventory' => 'menu.inventory', 'ingredients' => 'menu.ingredient', 'recipes' => 'menu.recipes', 'discounts' => 'menu.discount']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $route): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                <a href="<?php echo e(route($route)); ?>" class="rounded-lg px-4 py-2 <?php echo e($section === $label ? 'bg-blue-700 text-white' : 'border border-slate-300 bg-white text-slate-600 hover:bg-slate-100'); ?>"><?php echo e(ucfirst($label)); ?></a>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
        </nav>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('message')): ?><div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-blue-900"><?php echo e(session('message')); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?><div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700"><?php echo e($errors->first()); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($section === 'categories'): ?>
            <form wire:submit="saveCategory" class="flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><input wire:model="categoryName" placeholder="Category name" class="min-w-56 flex-1 rounded-lg border border-slate-300 px-3 py-2"><button class="rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white"><?php echo e($categoryId ? 'Update' : 'Add'); ?> category</button></form>
            <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white px-5 shadow-sm"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $category): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><div class="flex items-center justify-between gap-4 py-4"><span><?php echo e($category->name); ?></span><div class="flex gap-3 text-sm"><button wire:click="editCategory(<?php echo e($category->id); ?>)" class="text-blue-700">Edit</button><button wire:click="deleteCategory(<?php echo e($category->id); ?>)" wire:confirm="Delete category?" class="text-red-600">Delete</button></div></div><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></div>
        <?php elseif($section === 'products'): ?>
            <form wire:submit="saveProduct" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2"><input wire:model="productName" placeholder="Product name" class="rounded-lg border border-slate-300 px-3 py-2"><select wire:model="productCategoryId" class="rounded-lg border border-slate-300 px-3 py-2"><option value="">Category</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $category): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><option value="<?php echo e($category->id); ?>"><?php echo e($category->name); ?></option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></select><input wire:model="productPrice" type="number" min="0" step="0.01" placeholder="Price" class="rounded-lg border border-slate-300 px-3 py-2"><input wire:model="productImage" type="file" accept="image/*" class="rounded-lg border border-slate-300 px-3 py-2"><button class="rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white"><?php echo e($productId ? 'Update' : 'Add'); ?> product</button></form>
            <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white px-5 shadow-sm"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><div class="flex items-center justify-between gap-4 py-4"><div><strong><?php echo e($product->name); ?></strong><p class="text-sm text-slate-500"><?php echo e($product->category?->name); ?> · <?php echo e(config('pos.currency')); ?> <?php echo e(number_format($product->price, 0)); ?></p></div><div class="flex gap-3 text-sm"><button wire:click="editProduct(<?php echo e($product->id); ?>)" class="text-blue-700">Edit</button><button wire:click="deleteProduct(<?php echo e($product->id); ?>)" wire:confirm="Delete product?" class="text-red-600">Delete</button></div></div><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></div>
        <?php elseif($section === 'inventory'): ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stockProductId): ?><form wire:submit="saveStock" class="flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><label class="text-sm">On hand<input wire:model="productStock" type="number" min="0" class="ml-2 rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm">Low threshold<input wire:model="productThreshold" type="number" min="0" class="ml-2 rounded-lg border border-slate-300 px-3 py-2"></label><button class="rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white">Save stock</button></form><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white px-5 shadow-sm"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><div class="flex items-center justify-between gap-4 py-4"><span><?php echo e($product->name); ?></span><span class="text-sm text-slate-600"><?php echo e($product->current_stock); ?> on hand · <?php echo e($product->reserved_stock); ?> reserved</span><button wire:click="editStock(<?php echo e($product->id); ?>)" class="text-sm font-semibold text-blue-700">Adjust</button></div><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></div>
        <?php elseif($section === 'ingredients'): ?>
            <form wire:submit="saveIngredient" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2"><input wire:model="ingredientName" placeholder="Ingredient name" class="rounded-lg border border-slate-300 px-3 py-2"><input wire:model="ingredientUnit" placeholder="Unit, e.g. g or ml" class="rounded-lg border border-slate-300 px-3 py-2"><label class="text-sm">On hand<input wire:model="ingredientStock" type="number" min="0" step="0.01" class="ml-2 rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm">Low threshold<input wire:model="ingredientThreshold" type="number" min="0" step="0.01" class="ml-2 rounded-lg border border-slate-300 px-3 py-2"></label><button class="rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white"><?php echo e($ingredientId ? 'Update' : 'Add'); ?> ingredient</button></form>
            <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white px-5 shadow-sm"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $ingredients; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ingredient): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><div class="flex items-center justify-between gap-4 py-4"><span><?php echo e($ingredient->name); ?></span><span class="text-sm text-slate-600"><?php echo e($ingredient->current_stock); ?> <?php echo e($ingredient->unit); ?> · <?php echo e($ingredient->reserved_stock); ?> reserved</span><div class="flex gap-3 text-sm"><button wire:click="editIngredient(<?php echo e($ingredient->id); ?>)" class="text-blue-700">Edit</button><button wire:click="deleteIngredient(<?php echo e($ingredient->id); ?>)" wire:confirm="Delete ingredient?" class="text-red-600">Delete</button></div></div><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></div>
        <?php elseif($section === 'recipes'): ?>
            <form wire:submit="saveRecipe" class="flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><select wire:model="recipeProductId" class="rounded-lg border border-slate-300 px-3 py-2"><option value="">Product</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><option value="<?php echo e($product->id); ?>"><?php echo e($product->name); ?></option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></select><select wire:model="recipeIngredientId" class="rounded-lg border border-slate-300 px-3 py-2"><option value="">Ingredient</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $ingredients; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ingredient): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><option value="<?php echo e($ingredient->id); ?>"><?php echo e($ingredient->name); ?></option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></select><input wire:model="recipeQuantity" type="number" step="0.01" min="0.01" placeholder="Qty per product" class="rounded-lg border border-slate-300 px-3 py-2"><button class="rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white">Save recipe</button></form>
            <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white px-5 shadow-sm"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $recipes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $recipe): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><div class="flex items-center justify-between gap-4 py-4"><span><?php echo e($recipe->product?->name); ?> · <?php echo e($recipe->inventoryItem?->name); ?> · <?php echo e($recipe->quantity_required); ?> <?php echo e($recipe->inventoryItem?->unit); ?></span><button wire:click="deleteRecipe(<?php echo e($recipe->product_id); ?>, <?php echo e($recipe->inventory_item_id); ?>)" wire:confirm="Remove recipe line?" class="text-sm text-red-600">Remove</button></div><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></div>
        <?php else: ?>
            <form wire:submit="saveDiscount" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2"><input wire:model="discountName" placeholder="Discount name" class="rounded-lg border border-slate-300 px-3 py-2"><select wire:model="discountType" class="rounded-lg border border-slate-300 px-3 py-2"><option value="percentage">Percentage</option><option value="fixed_amount">Fixed amount</option></select><input wire:model="discountValue" type="number" min="0" step="0.01" placeholder="Value" class="rounded-lg border border-slate-300 px-3 py-2"><select wire:model.live="scheduleType" class="rounded-lg border border-slate-300 px-3 py-2"><option value="interval">Date interval</option><option value="routine">Weekly routine</option></select><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($scheduleType === 'interval'): ?><label class="text-sm">Start date<input wire:model="startDate" type="date" class="ml-2 rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm">End date<input wire:model="endDate" type="date" class="ml-2 rounded-lg border border-slate-300 px-3 py-2"></label><?php else: ?><select wire:model="routineDay" class="rounded-lg border border-slate-300 px-3 py-2"><option value="">Day of week</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $day => $name): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><option value="<?php echo e($day); ?>"><?php echo e($name); ?></option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></select><label class="text-sm">Start time<input wire:model="startTime" type="time" class="ml-2 rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm">End time<input wire:model="endTime" type="time" class="ml-2 rounded-lg border border-slate-300 px-3 py-2"></label><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><label class="text-sm">Products<select wire:model="selectedProducts" multiple class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><option value="<?php echo e($product->id); ?>"><?php echo e($product->name); ?></option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></select></label><label class="flex items-center gap-2 text-sm"><input wire:model="discountActive" type="checkbox">Active</label><button class="rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white"><?php echo e($discountId ? 'Update' : 'Add'); ?> discount</button></form>
            <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white px-5 shadow-sm"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $discounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $discount): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><div class="flex items-center justify-between gap-4 py-4"><div><strong><?php echo e($discount->name); ?></strong><p class="text-sm text-slate-500"><?php echo e($discount->discount_value); ?> <?php echo e($discount->discount_type); ?> · <?php echo e($discount->products->count()); ?> products · <?php echo e($discount->is_active ? 'Active' : 'Inactive'); ?></p></div><div class="flex gap-3 text-sm"><button wire:click="editDiscount(<?php echo e($discount->id); ?>)" class="text-blue-700">Edit</button><button wire:click="toggleDiscount(<?php echo e($discount->id); ?>)" class="text-blue-700">Toggle</button><button wire:click="deleteDiscount(<?php echo e($discount->id); ?>)" wire:confirm="Delete discount?" class="text-red-600">Delete</button></div></div><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </main>
</div>
<?php /**PATH /var/www/html/resources/views/pages/menu/manage.blade.php ENDPATH**/ ?>