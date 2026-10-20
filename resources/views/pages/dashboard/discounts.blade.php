<?php

use App\Domain\Catalog\ManageCatalog;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
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
        $discountRows = Discount::query()->withCount('products')
                ->when(trim($this->discountSearch) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($this->discountSearch).'%'))
                ->when(in_array($this->discountStatusFilter, ['active', 'inactive']), fn (Builder $query): Builder => $query->where('is_active', $this->discountStatusFilter === 'active'))
                ->when(in_array($this->discountScheduleFilter, ['interval', 'routine']), fn (Builder $query): Builder => $query->where('schedule_type', $this->discountScheduleFilter))
                ->orderBy('name')->orderBy('id')->paginate(10, pageName: 'discountsPage');
        $discountOverview = $discountRows->getCollection()->mapWithKeys(function (Discount $discount): array {
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
        })->all();

        return [
            'discountRows' => $discountRows,
            'discountOverview' => $discountOverview,
            'hasDiscounts' => Discount::exists(),
            'discountProductResults' => $this->showDiscountModal ? $this->discountProductQuery()->get() : collect(),
            'categories' => Category::orderBy('name')->get(),
            'products' => Product::with('category')->orderBy('name')->orderBy('id')->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        @if(session('message'))<div role="status" class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ session('message') }}</div>@endif
        @if($errors->any() && ! $showDiscountModal && ! $showDiscountDeleteModal)<div role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $errors->first() }}</div>@endif

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
                            <th scope="col" class="w-14 pb-5 pr-3">No.</th>
                            <th scope="col" class="w-[19%] pb-5 pl-2 pr-4">Nama &amp; nilai</th>
                            <th scope="col" class="w-[24%] pb-5 pr-4">Jadwal</th>
                            <th scope="col" class="w-[10%] pb-5 pr-4">Produk</th>
                            <th scope="col" class="w-[13%] pb-5 pr-4">Status</th>
                            <th scope="col" class="w-[29%] pb-5 pr-2 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-pos-border">
                        @foreach($discountRows as $discount)
                            <tr wire:key="discount-{{ $discount->id }}" class="hover:bg-pos-soft/50">
                                <td class="py-6 pr-3 text-sm text-pos-muted tabular-nums">{{ $discountRows->firstItem() + $loop->index }}</td>
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
    </main>
</div>
