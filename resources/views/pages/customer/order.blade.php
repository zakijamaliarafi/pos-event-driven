<?php

use App\Domain\Catalog\ProductAvailability;
use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\SubmissionConflict;
use App\Livewire\Concerns\RecoversOrderSubmissions;
use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    use RecoversOrderSubmissions;

    private const TERMINAL_STATUSES = ['completed', 'rejected', 'expired', 'cancelled'];

    #[Locked]
    public string $deviceId = '';

    #[Locked]
    public ?int $linkedTableNumber = null;

    #[Locked]
    public ?int $submittedOrderId = null;

    public string $customerName = '';
    public string $customerPhone = '';
    public string $tableNumber = '';
    public string $search = '';
    public string $categoryId = '';
    public array $quantities = [];
    public string $message = '';
    public bool $showCart = false;
    public bool $showTracking = false;

    public function mount(): void
    {
        $this->resetSubmission();
        $stored = request()->cookie('pos_device');
        $this->deviceId = is_string($stored) && Str::isUuid($stored) ? $stored : (string) Str::uuid();
        Cookie::queue('pos_device', $this->deviceId, 1440);

        $table = request()->query('table');
        if (is_string($table) && preg_match('/^[1-9]\d*$/D', $table)) {
            $parsed = filter_var($table, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            $this->linkedTableNumber = $parsed === false ? null : $parsed;
        }
    }

    #[On('customer-order-view-changed')]
    public function refreshOrders(): void {}

    public function resetFilters(): void
    {
        $this->reset('search', 'categoryId');
    }

    public function increment(int $productId, ProductAvailability $availability): void
    {
        $product = Product::with('recipes.inventoryItem')->find($productId);
        $quantity = $this->validQuantity($this->quantities[$productId] ?? 0);
        $limit = $product ? min(100, $availability->availableQuantity($product)) : 0;

        if ($quantity !== null && $quantity < $limit) {
            $this->quantities[$productId] = $quantity + 1;
            $this->resetValidation('cart');
        }
    }

    public function decrement(int $productId): void
    {
        $quantity = $this->validQuantity($this->quantities[$productId] ?? 0);
        if ($quantity !== null && $quantity > 1) {
            $this->quantities[$productId] = $quantity - 1;
        } else {
            unset($this->quantities[$productId]);
        }
        $this->resetValidation('cart');
    }

    public function remove(string $key): void
    {
        unset($this->quantities[$key]);
        $this->resetValidation();
    }

    public function submit(SubmitOrder $orders): void
    {
        $this->resetValidation();
        $this->submissionNotice = '';

        try {
            if ($this->submissionKey === '') {
                throw ValidationException::withMessages(['cart' => __('orders.errors.submission_key_invalid')]);
            }
            $cart = [];
            foreach ($this->quantities as $id => $quantity) {
                $parsed = $this->validQuantity($quantity);
                if ($parsed === 0) {
                    continue;
                }
                if (! ctype_digit((string) $id) || (int) $id < 1 || $parsed === null || $parsed > 100) {
                    throw ValidationException::withMessages(['cart' => __('orders.errors.invalid_quantity')]);
                }
                $cart[] = ['id' => (int) $id, 'qty' => $parsed];
            }

            if ($cart === []) {
                throw ValidationException::withMessages(['cart' => __('orders.errors.empty_cart')]);
            }

            $data = Validator::make([
                'table_number' => $this->linkedTableNumber ?? $this->tableNumber,
                'customer_name' => $this->customerName,
                'customer_phone' => $this->customerPhone,
            ], [
                'table_number' => ['required', 'integer', 'min:1', 'max:2147483647'],
                'customer_name' => ['required', 'string', 'max:100'],
                'customer_phone' => ['nullable', 'string', 'max:20'],
            ])->validate();

            $order = $orders->handle($data + ['order_type' => 'dine_in', 'cart' => $cart], $this->submissionKey, deviceId: $this->deviceId);
        } catch (SubmissionConflict $exception) {
            $this->recoveryOrderId = $exception->orderId;
            $this->addError('cart', $exception->getMessage());
            $this->dispatch('customer-checkout-invalid');

            return;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
            $this->dispatch('customer-checkout-invalid');

            return;
        }

        $this->resetSubmission();
        $this->quantities = [];
        $this->submittedOrderId = $order->id;
        $this->showCart = false;
        $this->showTracking = true;
        $this->message = __('orders.messages.customer_submitted', ['number' => $order->order_number]);
        $this->dispatch('customer-order-submitted', orderId: $order->id);
    }

    protected function submissionActor(): array
    {
        return ['cashierId' => null, 'deviceId' => $this->deviceId];
    }

    protected function showPreviousSubmission(Order $order): void
    {
        $this->submittedOrderId = $order->id;
        $this->showCart = false;
        $this->showTracking = true;
    }

    protected function submissionInvalidEvent(): string
    {
        return 'customer-checkout-invalid';
    }

    public function cancel(int $orderId, TransitionOrder $orders): void
    {
        Order::query()->whereKey($orderId)->where('device_id', $this->deviceId)->firstOrFail();
        $this->resetValidation('order');

        try {
            $orders->cancel($orderId);
            $this->message = __('orders.messages.customer_cancelled');
        } catch (ValidationException $exception) {
            $this->addError('order', $exception->getMessage());
        }
    }

    private function validQuantity(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $quantity = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $quantity === false ? null : $quantity;
    }

    public function with(ProductAvailability $availability, SubmitOrder $orders): array
    {
        $catalog = Product::with(['category', 'discounts', 'recipes.inventoryItem'])->orderBy('name')->orderBy('id')->get();
        $availableQuantities = $catalog->mapWithKeys(fn (Product $product): array => [$product->id => $availability->availableQuantity($product)]);
        $available = $catalog->filter(fn (Product $product): bool => $availableQuantities[$product->id] > 0);
        $categories = $available->pluck('category')->filter()->unique('id')->sortBy(fn ($category): string => $category->name)->values();
        $needle = mb_strtolower(trim($this->search));
        $products = $available->filter(fn (Product $product): bool =>
            ($this->categoryId === '' || (string) $product->category_id === $this->categoryId)
            && ($needle === '' || str_contains(mb_strtolower($product->name), $needle))
        );

        $selectedIds = array_filter(array_keys($this->quantities), fn ($id): bool => ctype_digit((string) $id) && (int) $id > 0);
        $selected = $catalog->keyBy('id');
        $missingIds = array_diff($selectedIds, $selected->keys()->all());
        if ($missingIds !== []) {
            $selected = $selected->union(Product::withTrashed()->with(['discounts', 'recipes.inventoryItem'])->whereIn('id', $missingIds)->get()->keyBy('id'));
        }

        $cartRows = collect($this->quantities)->map(function ($value, $id) use ($selected, $availability): ?array {
            $quantity = $this->validQuantity($value);
            if ($quantity === 0) {
                return null;
            }
            $product = ctype_digit((string) $id) ? $selected->get((int) $id) : null;
            $portions = $product && ! $product->trashed() ? $availability->availableQuantity($product) : 0;
            $price = $product ? ProductAvailability::hundredths($product->active_price) : 0;

            return [
                'key' => (string) $id,
                'product' => $product,
                'name' => $product?->name ?? 'Produk tidak tersedia',
                'quantity' => $quantity ?? 0,
                'limit' => min(100, $portions),
                'price' => $price,
                'subtotal' => $price * min($quantity ?? 0, 100),
                'invalid' => $quantity === null || $quantity > min(100, $portions),
            ];
        })->filter()->values();

        $owned = Order::query()->where('device_id', $this->deviceId);
        $activeOrders = (clone $owned)->whereNotIn('status', self::TERMINAL_STATUSES)->latest()->orderByDesc('id')->get();
        $historyOrders = (clone $owned)->whereIn('status', self::TERMINAL_STATUSES)->latest()->orderByDesc('id')->limit(10)->get();

        return [
            'recoveryOrder' => $this->submissionRecoveryOrder($orders),
            'products' => $products,
            'categories' => $categories,
            'availableQuantities' => $availableQuantities,
            'hasAvailableProducts' => $available->isNotEmpty(),
            'cartRows' => $cartRows,
            'cartQuantity' => $cartRows->sum('quantity'),
            'cartTotal' => $cartRows->sum('subtotal'),
            'cartBlocked' => $cartRows->isEmpty() || $cartRows->contains('invalid', true),
            'activeOrders' => $activeOrders,
            'historyOrders' => $historyOrders,
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink" x-data data-customer-order>
    <x-pos-nav :customer-toolbar="true">
        <x-slot:customerContext>
            <span class="inline-flex min-h-8 max-w-full items-center rounded-lg border border-pos-border-strong bg-pos-soft px-3 text-sm font-semibold text-pos-link" data-test="customer-table-badge">
                {{ $linkedTableNumber !== null ? 'Meja '.$linkedTableNumber : ($tableNumber !== '' ? 'Meja '.$tableNumber : 'Pilih meja') }}
            </span>
        </x-slot:customerContext>
        <x-slot:customerActions>
            <flux:button x-on:click="$wire.showTracking = true" class="min-h-11 border-pos-border-strong bg-pos-surface text-pos-link" data-customer-open="tracking" aria-label="Pesanan aktif: {{ $activeOrders->count() }}">
                <flux:icon.clipboard-document-list class="size-5 shrink-0" />
                <span><span class="sr-only sm:not-sr-only">Pesanan </span><span class="tabular-nums">({{ $activeOrders->count() }})</span></span>
            </flux:button>
            <flux:button variant="primary" x-on:click="if (window.matchMedia('(min-width: 1024px)').matches) { document.getElementById('desktop-cart-heading').focus(); document.getElementById('desktop-cart').scrollIntoView({ block: 'nearest' }); } else { $wire.showCart = true; }" class="min-h-11" data-customer-open="cart" aria-label="Keranjang: {{ $cartQuantity }} produk">
                <flux:icon.shopping-cart class="size-5 shrink-0" />
                <span><span class="sr-only sm:not-sr-only">Keranjang </span><span class="tabular-nums">({{ $cartQuantity }})</span></span>
            </flux:button>
        </x-slot:customerActions>
    </x-pos-nav>

    <main class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6">
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="min-w-0 space-y-5" aria-label="Menu produk" wire:loading.attr="aria-busy" wire:target="search,categoryId,refreshOrders">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest text-pos-link">Pesan mandiri</p>
                        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Pilih menu favorit Anda</h1>
                        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-pos-muted">Pesanan diverifikasi pramusaji. Bayar tunai di kasir setelah makan.</p>
                    </div>
                </div>
                @if($message)
                    <p role="status" class="rounded-xl border border-pos-border bg-pos-soft p-4 text-sm leading-relaxed">{{ $message }}</p>
                @endif
                <flux:input type="search" label="Cari menu" placeholder="Cari nama menu…" wire:model.live.debounce.300ms="search" class="min-h-11" />
                <div class="flex gap-2 overflow-x-auto pb-2" role="group" aria-label="Kategori menu">
                    <button type="button" wire:click="$set('categoryId', '')" aria-pressed="{{ $categoryId === '' ? 'true' : 'false' }}" class="min-h-11 shrink-0 rounded-full border px-5 text-sm font-semibold {{ $categoryId === '' ? 'border-pos-action bg-pos-action text-pos-action-foreground' : 'border-pos-border bg-pos-surface text-pos-muted hover:border-pos-border-strong' }}">Semua</button>
                    @foreach($categories as $category)
                        <button type="button" wire:key="customer-category-{{ $category->id }}" wire:click="$set('categoryId', '{{ $category->id }}')" aria-pressed="{{ $categoryId === (string) $category->id ? 'true' : 'false' }}" class="min-h-11 max-w-64 shrink-0 break-words rounded-full border px-5 text-sm font-semibold {{ $categoryId === (string) $category->id ? 'border-pos-action bg-pos-action text-pos-action-foreground' : 'border-pos-border bg-pos-surface text-pos-muted hover:border-pos-border-strong' }}">{{ $category->name }}</button>
                    @endforeach
                </div>
                <p wire:loading.delay wire:target="search,categoryId,refreshOrders" role="status" class="text-sm text-pos-muted">Memuat menu…</p>

                <div class="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 sm:gap-4 md:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3">
                    @forelse($products as $product)
                        @php($quantity = $cartRows->firstWhere('key', (string) $product->id)['quantity'] ?? 0)
                        <article wire:key="customer-product-{{ $product->id }}" class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-pos-border bg-pos-surface shadow-sm">
                            <x-customer-product-image :path="$product->image_url" wire:key="customer-image-menu-{{ $product->id }}-{{ md5((string) $product->image_url) }}" class="aspect-[6/5] w-full" />
                            <div class="flex flex-1 flex-col gap-2 p-3 sm:p-4">
                                <h2 class="break-words font-semibold leading-snug">{{ $product->name }}</h2>
                                <p class="break-words text-base font-bold text-pos-link">{{ config('pos.currency') }} {{ number_format($product->active_price, 0) }}</p>
                                <p class="text-xs text-pos-muted">Tersedia: {{ $availableQuantities[$product->id] }}</p>
                                <div class="mt-auto pt-2">
                                    @if($quantity > 0)
                                        <x-customer-quantity :product-id="$product->id" :name="$product->name" :quantity="$quantity" :limit="min(100, $availableQuantities[$product->id])" />
                                    @else
                                        <flux:button wire:click="increment({{ $product->id }})" wire:loading.attr="disabled" wire:target="checkSubmission,viewPreviousSubmission,startNewSubmission,submit,increment({{ $product->id }})" class="min-h-11 w-full" aria-label="Tambah {{ $product->name }} ke keranjang">Tambah</flux:button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="col-span-full rounded-2xl border border-dashed border-pos-border-strong bg-pos-surface p-8 text-center">
                            <flux:icon.shopping-bag class="mx-auto mb-3 size-9 text-pos-muted" />
                            <h2 class="font-semibold">{{ $hasAvailableProducts ? 'Menu tidak ditemukan' : 'Belum ada produk yang tersedia.' }}</h2>
                            <p class="mt-2 text-sm text-pos-muted">{{ $hasAvailableProducts ? 'Coba nama menu atau kategori lain.' : 'Silakan tanyakan ketersediaan menu kepada pramusaji.' }}</p>
                            @if($hasAvailableProducts)
                                <flux:button wire:click="resetFilters" class="mt-4 min-h-11">Reset pencarian</flux:button>
                            @endif
                        </div>
                    @endforelse
                </div>
            </section>

            <aside id="desktop-cart" class="sticky hidden min-w-0 overflow-hidden rounded-2xl border border-pos-border bg-pos-surface shadow-sm lg:block" aria-labelledby="desktop-cart-heading">
                <x-customer-cart :recovery-order="$recoveryOrder" context="desktop" :rows="$cartRows" :total="$cartTotal" :blocked="$cartBlocked" :linked-table="$linkedTableNumber" />
            </aside>
        </div>
    </main>

    <flux:modal name="customer-cart" wire:model.self="showCart" :closable="false" class="customer-sheet bg-pos-surface! p-0!" data-customer-cart aria-labelledby="mobile-cart-heading">
        <x-customer-cart :recovery-order="$recoveryOrder" context="mobile" :rows="$cartRows" :total="$cartTotal" :blocked="$cartBlocked" :linked-table="$linkedTableNumber" />
    </flux:modal>

    <flux:modal name="customer-tracking" wire:model.self="showTracking" :closable="false" class="customer-sheet customer-tracking bg-pos-surface! p-0!" data-customer-tracking aria-labelledby="customer-tracking-heading">
        <div class="flex max-h-[90dvh] flex-col">
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-pos-border p-5">
                <h2 id="customer-tracking-heading" class="text-xl font-bold" tabindex="-1" autofocus>Pesanan Anda</h2>
                <flux:modal.close><flux:button square variant="ghost" icon="x-mark" class="min-h-11 min-w-11" aria-label="Tutup pesanan" /></flux:modal.close>
            </div>
            <div class="min-h-0 space-y-5 overflow-y-auto p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:p-5" aria-live="polite">
                <x-order-submission-summary :order="$recoveryOrder" />
                @error('order')<p role="alert" class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-3 text-sm text-pos-danger">{{ $message }}</p>@enderror
                <h3 class="font-semibold">Pesanan aktif ({{ $activeOrders->count() }})</h3>
                @forelse($activeOrders as $order)
                    <x-customer-order-status :order="$order" :highlighted="$order->id === $submittedOrderId" wire:key="customer-active-order-{{ $order->id }}" />
                @empty
                    <p class="rounded-xl bg-pos-soft p-4 text-sm text-pos-muted">Belum ada pesanan aktif. Pilih menu untuk mulai memesan.</p>
                @endforelse
                <details class="group border-t border-pos-border pt-4" wire:key="customer-order-history" wire:ignore.self>
                    <summary class="min-h-11 cursor-pointer py-3 font-semibold">Riwayat terbaru ({{ $historyOrders->count() }})</summary>
                    <div class="mt-3 space-y-3">
                        @forelse($historyOrders as $order)
                            <x-customer-order-status :order="$order" :highlighted="$order->id === $submittedOrderId" wire:key="customer-history-order-{{ $order->id }}" />
                        @empty
                            <p class="text-sm text-pos-muted">Belum ada riwayat pesanan.</p>
                        @endforelse
                    </div>
                </details>
            </div>
        </div>
    </flux:modal>

    <div hidden aria-hidden="true" data-customer-subscriptions>
        @foreach($activeOrders->concat($historyOrders) as $order)
            <span data-pos-order-id="{{ $order->id }}" wire:key="customer-subscription-{{ $order->id }}"></span>
        @endforeach
    </div>
</div>
