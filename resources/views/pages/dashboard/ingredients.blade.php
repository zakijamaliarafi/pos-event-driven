<?php

use App\Domain\Catalog\ManageCatalog;
use App\Domain\Catalog\ProductAvailability;
use App\Models\InventoryItem;
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

    public function with(): array
    {
        $ingredientRows = InventoryItem::query()->withCount('recipes')
                ->when(trim($this->ingredientSearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->ingredientSearch).'%'))
                ->when($this->ingredientStatusFilter === 'empty', fn (Builder $query): Builder => $query->whereRaw('current_stock - reserved_stock <= 0'))
                ->when($this->ingredientStatusFilter === 'low', fn (Builder $query): Builder => $query->whereRaw('current_stock - reserved_stock > 0 AND current_stock - reserved_stock <= low_stock_threshold'))
                ->when($this->ingredientStatusFilter === 'sufficient', fn (Builder $query): Builder => $query->whereRaw('current_stock - reserved_stock > 0 AND current_stock - reserved_stock > low_stock_threshold'))
                ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'ingredientsPage');
        $ingredientOverview = $ingredientRows->getCollection()->mapWithKeys(function (InventoryItem $ingredient): array {
            $available = max(0, ProductAvailability::hundredths($ingredient->current_stock) - ProductAvailability::hundredths($ingredient->reserved_stock));

            return [$ingredient->id => [
                'available' => number_format($available / 100, 2, ',', '.'),
                'status' => $available === 0 ? 'Habis' : ($available <= ProductAvailability::hundredths($ingredient->low_stock_threshold) ? 'Rendah' : 'Cukup'),
            ]];
        })->all();

        return [
            'ingredientRows' => $ingredientRows,
            'ingredientOverview' => $ingredientOverview,
            'hasIngredients' => InventoryItem::exists(),
            'editingIngredient' => $this->showIngredientModal && $this->ingredientId !== null ? InventoryItem::find($this->ingredientId) : null,
            'deletingIngredient' => $this->showIngredientDeleteModal ? InventoryItem::withCount('recipes')->find($this->deletingIngredientId) : null,
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        @if(session('message'))<div role="status" class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ session('message') }}</div>@endif
        @if($errors->any() && ! $showIngredientModal && ! $showIngredientDeleteModal)<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $errors->first() }}</div>@endif

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
                        <tr><th scope="col" class="w-14 pb-4 pr-3">No.</th><th scope="col" class="w-[22%] pb-4">Bahan / satuan</th><th scope="col" class="w-[15%] pb-4">Stok total</th><th scope="col" class="w-[15%] pb-4">Dicadangkan</th><th scope="col" class="w-[15%] pb-4">Stok tersedia</th><th scope="col" class="w-[12%] pb-4">Status</th><th scope="col" class="pb-4 text-right">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-pos-border">
                        @foreach($ingredientRows as $ingredient)
                            @php
                                $overview = $ingredientOverview[$ingredient->id];
                            @endphp
                            <tr wire:key="ingredient-table-{{ $ingredient->id }}" class="hover:bg-pos-soft/50">
                                <td class="py-5 pr-3 text-sm text-pos-muted tabular-nums">{{ $ingredientRows->firstItem() + $loop->index }}</td>
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
    </main>
</div>
