<?php

use App\Domain\Catalog\ManageCatalog;
use App\Domain\Catalog\ProductAvailability;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

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

    public function with(): array
    {
        $recipeRows = Product::with(['category', 'recipes.inventoryItem'])
                ->when(trim($this->recipeSearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->recipeSearch).'%'))
                ->when($this->recipeCategoryFilter !== '', fn (Builder $query): Builder => $query->where('category_id', $this->recipeCategoryFilter))
                ->when($this->recipePresenceFilter === 'with', fn (Builder $query): Builder => $query->has('recipes'))
                ->when($this->recipePresenceFilter === 'without', fn (Builder $query): Builder => $query->doesntHave('recipes'))
                ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'recipesPage');
        $availability = new ProductAvailability;
        $recipeOverview = $recipeRows->getCollection()->mapWithKeys(fn (Product $product): array => [$product->id => [
            'status' => $product->recipes->isEmpty() ? 'Belum ada resep' : ($availability->hasValidRecipe($product) ? 'Resep tersedia' : 'Resep tidak valid'),
            'portions' => $availability->availableQuantity($product),
        ]])->all();

        return [
            'recipeProducts' => $recipeRows,
            'recipeOverview' => $recipeOverview,
            'hasRecipeProducts' => Product::exists(),
            'editingRecipeProduct' => $this->showRecipeModal ? Product::with('category')->find($this->editingRecipeProductId) : null,
            'categories' => Category::orderBy('name')->get(),
            'ingredients' => InventoryItem::orderBy('name')->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        @if(session('message'))<div role="status" class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ session('message') }}</div>@endif
        @if($errors->any() && ! $showRecipeModal)<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $errors->first() }}</div>@endif

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
    </main>
</div>
