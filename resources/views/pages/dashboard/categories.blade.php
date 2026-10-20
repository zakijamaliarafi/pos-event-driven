<?php

use App\Domain\Catalog\ManageCatalog;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

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

    public function with(): array
    {
        return [
            'categoryRows' => Category::query()->withCount('products')
                    ->when(trim($this->categorySearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->categorySearch).'%'))
                    ->when($this->categoryUsageFilter === 'used', fn (Builder $query): Builder => $query->has('products'))
                    ->when($this->categoryUsageFilter === 'empty', fn (Builder $query): Builder => $query->doesntHave('products'))
                    ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'categoriesPage'),
            'hasCategories' => Category::exists(),
            'deletingCategory' => $this->showCategoryDeleteModal ? Category::withCount('products')->find($this->deletingCategoryId) : null,
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        @if(session('message'))<div role="status" class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ session('message') }}</div>@endif
        @if($errors->any() && ! $showCategoryModal && ! $showCategoryDeleteModal)<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $errors->first() }}</div>@endif

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
    </main>
</div>
