<?php

use App\Domain\Catalog\ManageCatalog;
use App\Domain\Catalog\ProductAvailability;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads, WithPagination;

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

    public function with(): array
    {
        $productRows = Product::with(['category', 'recipes.inventoryItem'])
                ->when(trim($this->productSearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->productSearch).'%'))
                ->when($this->productCategoryFilter !== '', fn (Builder $query): Builder => $query->where('category_id', $this->productCategoryFilter))
                ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'productsPage');
        $productAvailability = new ProductAvailability;
        $productOverview = $productRows->getCollection()->mapWithKeys(fn (Product $product): array => [$product->id => [
            'status' => $product->recipes->isEmpty() ? 'Belum ada resep' : ($productAvailability->hasValidRecipe($product) ? 'Resep tersedia' : 'Resep tidak valid'),
            'portions' => $productAvailability->availableQuantity($product),
        ]])->all();

        return [
            'productRows' => $productRows,
            'productOverview' => $productOverview,
            'hasProducts' => Product::exists(),
            'deletingProduct' => $this->showProductDeleteModal ? Product::find($this->deletingProductId) : null,
            'categories' => Category::orderBy('name')->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        @if(session('message'))<div role="status" class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ session('message') }}</div>@endif
        @if($errors->any() && ! $showProductModal && ! $showProductDeleteModal)<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $errors->first() }}</div>@endif

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
    </main>
</div>
