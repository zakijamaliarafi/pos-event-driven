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
        session()->flash('message', __('catalog.messages.category_saved'));
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
        session()->flash('message', __('catalog.messages.product_saved'));
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
        session()->flash('message', __('catalog.messages.stock_adjusted'));
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
        session()->flash('message', __('catalog.messages.ingredient_saved'));
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
        session()->flash('message', __('catalog.messages.recipe_saved'));
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
        session()->flash('message', __('catalog.messages.discount_saved'));
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

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-pos-link">Pengelolaan</p><h1 class="mt-1 text-3xl font-bold">Katalog dan stok</h1></div>
        <nav class="flex flex-wrap gap-2 text-sm font-semibold">
            @foreach(['products' => 'menu.menu', 'categories' => 'menu.category', 'inventory' => 'menu.inventory', 'ingredients' => 'menu.ingredient', 'recipes' => 'menu.recipes', 'discounts' => 'menu.discount'] as $label => $route)
                <a href="{{ route($route) }}" class="rounded-lg px-4 py-2 {{ $section === $label ? 'bg-pos-action text-pos-action-foreground' : 'border border-pos-border-strong bg-pos-surface text-pos-muted hover:bg-pos-soft' }}">{{ __('catalog.sections.'.$label) }}</a>
            @endforeach
        </nav>
        @if(session('message'))<div class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ session('message') }}</div>@endif
        @if($errors->any())<div class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $errors->first() }}</div>@endif

        @if($section === 'categories')
            <form wire:submit="saveCategory" class="flex flex-wrap gap-3 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm"><input wire:model="categoryName" placeholder="Nama kategori" class="min-w-56 flex-1 rounded-lg border border-pos-border-strong px-3 py-2"><button class="rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground">{{ $categoryId ? 'Perbarui' : 'Tambah' }} kategori</button></form>
            <div class="divide-y divide-pos-border rounded-2xl border border-pos-border bg-pos-surface px-5 shadow-sm">@foreach($categories as $category)<div class="flex items-center justify-between gap-4 py-4"><span>{{ $category->name }}</span><div class="flex gap-3 text-sm"><button wire:click="editCategory({{ $category->id }})" class="text-pos-link">Ubah</button><button wire:click="deleteCategory({{ $category->id }})" wire:confirm="Hapus kategori ini?" class="text-pos-danger">Hapus</button></div></div>@endforeach</div>
        @elseif($section === 'products')
            <form wire:submit="saveProduct" class="grid gap-3 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm md:grid-cols-2"><input wire:model="productName" placeholder="Nama produk" class="rounded-lg border border-pos-border-strong px-3 py-2"><select wire:model="productCategoryId" class="rounded-lg border border-pos-border-strong px-3 py-2"><option value="">Kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select><input wire:model="productPrice" type="number" min="0" step="0.01" placeholder="Harga" class="rounded-lg border border-pos-border-strong px-3 py-2"><input wire:model="productImage" type="file" accept="image/*" class="rounded-lg border border-pos-border-strong px-3 py-2"><button class="rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground">{{ $productId ? 'Perbarui' : 'Tambah' }} produk</button></form>
            <div class="divide-y divide-pos-border rounded-2xl border border-pos-border bg-pos-surface px-5 shadow-sm">@foreach($products as $product)<div class="flex items-center justify-between gap-4 py-4"><div><strong>{{ $product->name }}</strong><p class="text-sm text-pos-muted">{{ $product->category?->name }} · {{ config('pos.currency') }} {{ number_format($product->price, 0) }}</p></div><div class="flex gap-3 text-sm"><button wire:click="editProduct({{ $product->id }})" class="text-pos-link">Ubah</button><button wire:click="deleteProduct({{ $product->id }})" wire:confirm="Hapus produk ini?" class="text-pos-danger">Hapus</button></div></div>@endforeach</div>
        @elseif($section === 'inventory')
            @if($stockProductId)<form wire:submit="saveStock" class="flex flex-wrap gap-3 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm"><label class="text-sm">Stok tersedia<input wire:model="productStock" type="number" min="0" class="ml-2 rounded-lg border border-pos-border-strong px-3 py-2"></label><label class="text-sm">Batas stok rendah<input wire:model="productThreshold" type="number" min="0" class="ml-2 rounded-lg border border-pos-border-strong px-3 py-2"></label><button class="rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground">Simpan stok</button></form>@endif
            <div class="divide-y divide-pos-border rounded-2xl border border-pos-border bg-pos-surface px-5 shadow-sm">@foreach($products as $product)<div class="flex items-center justify-between gap-4 py-4"><span>{{ $product->name }}</span><span class="text-sm text-pos-muted">{{ $product->current_stock }} tersedia · {{ $product->reserved_stock }} dicadangkan</span><button wire:click="editStock({{ $product->id }})" class="text-sm font-semibold text-pos-link">Sesuaikan</button></div>@endforeach</div>
        @elseif($section === 'ingredients')
            <form wire:submit="saveIngredient" class="grid gap-3 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm md:grid-cols-2"><input wire:model="ingredientName" placeholder="Nama bahan" class="rounded-lg border border-pos-border-strong px-3 py-2"><input wire:model="ingredientUnit" placeholder="Satuan, mis. g atau ml" class="rounded-lg border border-pos-border-strong px-3 py-2"><label class="text-sm">Stok tersedia<input wire:model="ingredientStock" type="number" min="0" step="0.01" class="ml-2 rounded-lg border border-pos-border-strong px-3 py-2"></label><label class="text-sm">Batas stok rendah<input wire:model="ingredientThreshold" type="number" min="0" step="0.01" class="ml-2 rounded-lg border border-pos-border-strong px-3 py-2"></label><button class="rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground">{{ $ingredientId ? 'Perbarui' : 'Tambah' }} bahan</button></form>
            <div class="divide-y divide-pos-border rounded-2xl border border-pos-border bg-pos-surface px-5 shadow-sm">@foreach($ingredients as $ingredient)<div class="flex items-center justify-between gap-4 py-4"><span>{{ $ingredient->name }}</span><span class="text-sm text-pos-muted">{{ $ingredient->current_stock }} {{ $ingredient->unit }} · {{ $ingredient->reserved_stock }} dicadangkan</span><div class="flex gap-3 text-sm"><button wire:click="editIngredient({{ $ingredient->id }})" class="text-pos-link">Ubah</button><button wire:click="deleteIngredient({{ $ingredient->id }})" wire:confirm="Hapus bahan ini?" class="text-pos-danger">Hapus</button></div></div>@endforeach</div>
        @elseif($section === 'recipes')
            <form wire:submit="saveRecipe" class="flex flex-wrap gap-3 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm"><select wire:model="recipeProductId" class="rounded-lg border border-pos-border-strong px-3 py-2"><option value="">Produk</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select><select wire:model="recipeIngredientId" class="rounded-lg border border-pos-border-strong px-3 py-2"><option value="">Bahan</option>@foreach($ingredients as $ingredient)<option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option>@endforeach</select><input wire:model="recipeQuantity" type="number" step="0.01" min="0.01" placeholder="Jumlah per produk" class="rounded-lg border border-pos-border-strong px-3 py-2"><button class="rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground">Simpan resep</button></form>
            <div class="divide-y divide-pos-border rounded-2xl border border-pos-border bg-pos-surface px-5 shadow-sm">@foreach($recipes as $recipe)<div class="flex items-center justify-between gap-4 py-4"><span>{{ $recipe->product?->name }} · {{ $recipe->inventoryItem?->name }} · {{ $recipe->quantity_required }} {{ $recipe->inventoryItem?->unit }}</span><button wire:click="deleteRecipe({{ $recipe->product_id }}, {{ $recipe->inventory_item_id }})" wire:confirm="Hapus komponen resep ini?" class="text-sm text-pos-danger">Hapus</button></div>@endforeach</div>
        @else
            <form wire:submit="saveDiscount" class="grid gap-3 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm md:grid-cols-2"><input wire:model="discountName" placeholder="Nama diskon" class="rounded-lg border border-pos-border-strong px-3 py-2"><select wire:model="discountType" class="rounded-lg border border-pos-border-strong px-3 py-2"><option value="percentage">Persentase</option><option value="fixed_amount">Nominal tetap</option></select><input wire:model="discountValue" type="number" min="0" step="0.01" placeholder="Nilai" class="rounded-lg border border-pos-border-strong px-3 py-2"><select wire:model.live="scheduleType" class="rounded-lg border border-pos-border-strong px-3 py-2"><option value="interval">Rentang tanggal</option><option value="routine">Rutin mingguan</option></select>@if($scheduleType === 'interval')<label class="text-sm">Tanggal mulai<input wire:model="startDate" type="date" class="ml-2 rounded-lg border border-pos-border-strong px-3 py-2"></label><label class="text-sm">Tanggal selesai<input wire:model="endDate" type="date" class="ml-2 rounded-lg border border-pos-border-strong px-3 py-2"></label>@else<select wire:model="routineDay" class="rounded-lg border border-pos-border-strong px-3 py-2"><option value="">Hari</option>@foreach(['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'] as $day => $name)<option value="{{ $day }}">{{ $name }}</option>@endforeach</select><label class="text-sm">Jam mulai<input wire:model="startTime" type="time" class="ml-2 rounded-lg border border-pos-border-strong px-3 py-2"></label><label class="text-sm">Jam selesai<input wire:model="endTime" type="time" class="ml-2 rounded-lg border border-pos-border-strong px-3 py-2"></label>@endif<label class="text-sm">Produk<select wire:model="selectedProducts" multiple class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2">@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></label><label class="flex items-center gap-2 text-sm"><input wire:model="discountActive" type="checkbox">Aktif</label><button class="rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground">{{ $discountId ? 'Perbarui' : 'Tambah' }} diskon</button></form>
            <div class="divide-y divide-pos-border rounded-2xl border border-pos-border bg-pos-surface px-5 shadow-sm">@foreach($discounts as $discount)<div class="flex items-center justify-between gap-4 py-4"><div><strong>{{ $discount->name }}</strong><p class="text-sm text-pos-muted">{{ $discount->discount_value }} {{ $discount->discount_type === 'percentage' ? 'persen' : 'nominal tetap' }} · {{ $discount->products->count() }} produk · {{ $discount->is_active ? 'Aktif' : 'Tidak aktif' }}</p></div><div class="flex gap-3 text-sm"><button wire:click="editDiscount({{ $discount->id }})" class="text-pos-link">Ubah</button><button wire:click="toggleDiscount({{ $discount->id }})" class="text-pos-link">Ubah status</button><button wire:click="deleteDiscount({{ $discount->id }})" wire:confirm="Hapus diskon ini?" class="text-pos-danger">Hapus</button></div></div>@endforeach</div>
        @endif
    </main>
</div>
