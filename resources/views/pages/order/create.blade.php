<?php

use App\Domain\Catalog\ProductAvailability;
use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    private const ACTIVE_STATUSES = ['awaiting_inventory', 'awaiting_verification', 'verifying', 'waiting_payment', 'pending', 'preparing', 'ready', 'awaiting_payment'];

    public string $customerName = '';

    public string $customerPhone = '';

    public string $orderType = 'dine_in';

    public string $tableNumber = '';

    public array $quantities = [];

    public string $search = '';

    public string $categoryId = '';

    public string $workspace = 'sale';

    public string $orderSearch = '';

    public string $orderFilter = 'all';

    public bool $showCart = false;

    public bool $showConfirmation = false;

    #[Locked]
    public string $message = '';

    #[Locked]
    public ?int $submittedOrderId = null;

    #[Locked]
    public ?int $confirmationOrderId = null;

    #[Locked]
    public string $confirmationAction = '';

    #[Locked]
    public string $confirmationOrderNumber = '';

    public function mount(): void
    {
        $this->authorizeCashier();
    }

    #[On('order-view-changed')]
    public function refreshOrders(): void
    {
        $this->authorizeCashier();
        $lastPage = max(1, (int) ceil($this->filteredOrders()->count() / 10));
        if ($this->getPage('ordersPage') > $lastPage) {
            $this->setPage($lastPage, 'ordersPage');
        }
    }

    public function setWorkspace(string $workspace): void
    {
        $this->authorizeCashier();
        abort_unless(in_array($workspace, ['sale', 'orders'], true), 422);
        $this->workspace = $workspace;
        $this->showCart = false;
    }

    public function resetFilters(): void
    {
        $this->authorizeCashier();
        $this->reset('search', 'categoryId');
    }

    public function updatedOrderSearch(): void
    {
        $this->resetPage('ordersPage');
    }

    public function updatedOrderFilter(): void
    {
        $this->resetPage('ordersPage');
    }

    public function resetOrderFilters(): void
    {
        $this->authorizeCashier();
        $this->reset('orderSearch', 'orderFilter');
        $this->resetPage('ordersPage');
    }

    public function increment(int $productId, ProductAvailability $availability): void
    {
        $this->authorizeCashier();
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
        $this->authorizeCashier();
        $quantity = $this->validQuantity($this->quantities[$productId] ?? 0);
        if ($quantity !== null && $quantity > 1) {
            $this->quantities[$productId] = $quantity - 1;
        } elseif ($quantity === 1 || $quantity === 0) {
            unset($this->quantities[$productId]);
        }
        $this->resetValidation('cart');
    }

    public function remove(string $key): void
    {
        $this->authorizeCashier();
        unset($this->quantities[$key]);
        $this->resetValidation('cart');
    }

    public function submit(SubmitOrder $orders, ProductAvailability $availability): void
    {
        $this->authorizeCashier();
        $this->resetValidation();
        try {
            $cart = [];
            foreach ($this->quantities as $id => $value) {
                $quantity = $this->validQuantity($value);
                if ($quantity === 0) {
                    continue;
                }
                if (! ctype_digit((string) $id) || (int) $id < 1 || $quantity === null || $quantity > 100) {
                    throw ValidationException::withMessages(['cart' => __('orders.errors.invalid_quantity')]);
                }
                $cart[] = ['id' => (int) $id, 'qty' => $quantity];
            }
            if ($cart === []) {
                throw ValidationException::withMessages(['cart' => __('orders.errors.empty_cart')]);
            }
            $data = Validator::make([
                'order_type' => $this->orderType,
                'table_number' => $this->orderType === 'dine_in' ? $this->tableNumber : null,
                'customer_name' => $this->customerName,
                'customer_phone' => $this->customerPhone,
            ], [
                'order_type' => ['required', 'in:dine_in,takeaway'],
                'table_number' => ['required_if:order_type,dine_in', 'nullable', 'integer', 'min:1', 'max:2147483647'],
                'customer_name' => ['required', 'string', 'max:100'],
                'customer_phone' => ['nullable', 'string', 'max:20'],
            ])->validate();
            $products = Product::with('recipes.inventoryItem')->whereIn('id', array_column($cart, 'id'))->get()->keyBy('id');
            foreach ($cart as $line) {
                $product = $products->get($line['id']);
                if (! $product || $availability->availableQuantity($product) < $line['qty']) {
                    throw ValidationException::withMessages(['cart' => __('orders.errors.cart_changed')]);
                }
            }
            $order = $orders->handle($data + ['cart' => $cart], cashierId: (int) Auth::id());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
            $this->dispatch('cashier-checkout-invalid');

            return;
        }
        $this->quantities = [];
        $this->showCart = false;
        $this->workspace = 'orders';
        $this->submittedOrderId = $order->id;
        $this->resetOrderFilters();
        $this->message = __('orders.messages.cashier_submitted', ['number' => $order->order_number]);
        $this->dispatch('cashier-sale-submitted', orderId: $order->id);
    }

    public function openPayment(int $orderId): void
    {
        $this->openConfirmation($orderId, 'pay');
    }

    public function openCancellation(int $orderId): void
    {
        $this->openConfirmation($orderId, 'cancel');
    }

    public function closeConfirmation(): void
    {
        $this->authorizeCashier();
        $this->reset('showConfirmation', 'confirmationOrderId', 'confirmationAction', 'confirmationOrderNumber');
        $this->resetValidation('confirmation');
        $this->dispatch('cashier-dialog-closed');
    }

    public function pay(int $orderId, RecordCashPayment $payments): void
    {
        $this->requireConfirmation($orderId, 'pay');
        $this->resetValidation('confirmation');
        try {
            $order = $payments->handle($orderId, (int) Auth::id(), "cash-order-{$orderId}");
            $this->message = $order->device_id === null
                ? __('orders.messages.cash_recorded_prepaid', ['number' => $order->order_number])
                : __('orders.messages.cash_recorded_customer', ['number' => $order->order_number]);
            $this->closeConfirmation();
            $this->refreshOrders();
        } catch (ValidationException $exception) {
            $this->addError('confirmation', collect($exception->errors())->flatten()->first());
            $this->dispatch('cashier-confirmation-invalid');
        } catch (ModelNotFoundException) {
            $this->addError('confirmation', 'Pesanan tidak ditemukan. Tutup dialog untuk melihat daftar terbaru.');
            $this->dispatch('cashier-confirmation-invalid');
        }
    }

    public function cancel(int $orderId, TransitionOrder $orders): void
    {
        $this->requireConfirmation($orderId, 'cancel');
        $this->resetValidation('confirmation');
        try {
            $order = $orders->cancel($orderId);
            $this->message = __('orders.messages.cashier_cancelled', ['number' => $order->order_number]);
            $this->closeConfirmation();
            $this->refreshOrders();
        } catch (ValidationException $exception) {
            $this->addError('confirmation', collect($exception->errors())->flatten()->first());
            $this->dispatch('cashier-confirmation-invalid');
        } catch (ModelNotFoundException) {
            $this->addError('confirmation', 'Pesanan tidak ditemukan. Tutup dialog untuk melihat daftar terbaru.');
            $this->dispatch('cashier-confirmation-invalid');
        }
    }

    private function authorizeCashier(): void
    {
        abort_unless(Auth::user()?->hasRole('cashier'), 403);
    }

    private function requireConfirmation(int $orderId, string $action): void
    {
        $this->authorizeCashier();
        abort_unless($this->showConfirmation && $this->confirmationOrderId === $orderId && $this->confirmationAction === $action, 403);
    }

    private function openConfirmation(int $orderId, string $action): void
    {
        $this->authorizeCashier();
        $this->resetValidation('confirmation');
        try {
            $order = $this->activeOrders()->findOrFail($orderId);
            $this->confirmationOrderId = $order->id;
            $this->confirmationOrderNumber = $order->order_number;
            $this->confirmationAction = $action;
            $this->showConfirmation = true;
            $this->dispatch('cashier-confirmation-opened');
        } catch (ModelNotFoundException) {
            $this->message = 'Pesanan tidak lagi aktif. Daftar pesanan telah diperbarui.';
            $this->refreshOrders();
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

    private function activeOrders(): Builder
    {
        return Order::query()->whereIn('status', self::ACTIVE_STATUSES)
            ->where(fn (Builder $query) => $query->whereDate('created_at', today())->orWhereNotNull('device_id'));
    }

    private function payableOrders(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->whereNull('device_id')->where('status', 'waiting_payment')->where('expires_at', '>=', now()))
            ->orWhere(fn (Builder $query) => $query->whereNotNull('device_id')->where('status', 'awaiting_payment')))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cash_payments')->whereColumn('cash_payments.order_id', 'orders.id'));
    }

    private function filteredOrders(): Builder
    {
        $query = $this->activeOrders();
        $search = trim($this->orderSearch);
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->whereLike('order_number', '%'.$search.'%')->orWhereLike('customer_name', '%'.$search.'%'));
        }
        if ($this->orderFilter === 'payable') {
            $this->payableOrders($query);
        }

        return $query;
    }

    private function orderDetails(Builder $query): Builder
    {
        return $query->select('orders.*')->selectSub(
            DB::table('cash_payments')->selectRaw('COUNT(*)')->whereColumn('cash_payments.order_id', 'orders.id'), 'has_cash_payment'
        )->with(['items.product' => fn ($query) => $query->withTrashed()]);
    }

    private function canPay(Order $order): bool
    {
        return ! $order->has_cash_payment && ($order->device_id === null
            ? $order->status === 'waiting_payment' && $order->expires_at !== null && ! $order->expires_at->isPast()
            : $order->status === 'awaiting_payment');
    }

    private function canCancel(Order $order): bool
    {
        return ! $order->has_cash_payment && in_array($order->status, $order->device_id === null
            ? ['awaiting_inventory', 'waiting_payment'] : ['awaiting_inventory', 'awaiting_verification'], true);
    }

    public function with(ProductAvailability $availability): array
    {
        $this->authorizeCashier();
        $catalog = Product::with(['category', 'discounts', 'recipes.inventoryItem'])->orderBy('name')->orderBy('id')->get();
        $availableQuantities = $catalog->mapWithKeys(fn (Product $product): array => [$product->id => $availability->availableQuantity($product)]);
        $available = $catalog->filter(fn (Product $product): bool => $availableQuantities[$product->id] > 0);
        $categories = $available->pluck('category')->filter()->unique('id')->sortBy('name')->values();
        $needle = mb_strtolower(trim($this->search));
        $products = $available->filter(fn (Product $product): bool => ($this->categoryId === '' || (string) $product->category_id === $this->categoryId)
            && ($needle === '' || str_contains(mb_strtolower($product->name), $needle))
        );
        $selected = $catalog->keyBy('id');
        $missingIds = array_diff(array_filter(array_keys($this->quantities), fn ($id): bool => ctype_digit((string) $id) && (int) $id > 0), $selected->keys()->all());
        if ($missingIds !== []) {
            $selected = $selected->union(Product::withTrashed()->with(['discounts', 'recipes.inventoryItem'])->whereIn('id', $missingIds)->get()->keyBy('id'));
        }
        $cartRows = collect($this->quantities)->map(function ($value, $id) use ($selected, $availability): ?array {
            $quantity = $this->validQuantity($value);
            if ($quantity === 0) {
                return null;
            }
            $product = ctype_digit((string) $id) ? $selected->get((int) $id) : null;
            $limit = $product && ! $product->trashed() ? min(100, $availability->availableQuantity($product)) : 0;
            $price = $product ? ProductAvailability::hundredths($product->active_price) : 0;

            return [
                'key' => (string) $id, 'product' => $product, 'name' => $product?->name ?? 'Produk tidak tersedia',
                'quantity' => $quantity, 'limit' => $limit, 'price' => $price,
                'subtotal' => $quantity !== null && $quantity <= 100 ? $price * $quantity : null,
                'invalid' => $quantity === null || $quantity > $limit || ! $product || $product->trashed(),
            ];
        })->filter()->values();
        $orderRows = $this->orderDetails($this->filteredOrders())->latest()->orderByDesc('id')->paginate(10, pageName: 'ordersPage');
        foreach ($orderRows as $order) {
            $order->setAttribute('can_pay', $this->canPay($order));
            $order->setAttribute('can_cancel', $this->canCancel($order));
        }
        $confirmationOrder = $this->confirmationOrderId === null ? null : $this->orderDetails(Order::query())->find($this->confirmationOrderId);
        $confirmationEligible = $confirmationOrder && ($this->confirmationAction === 'pay' ? $this->canPay($confirmationOrder) : $this->canCancel($confirmationOrder));

        return [
            'products' => $products, 'categories' => $categories, 'menuCount' => $available->count(),
            'availableQuantities' => $availableQuantities, 'cartRows' => $cartRows,
            'cartTotal' => (int) $cartRows->sum('subtotal'), 'cartCount' => (int) $cartRows->sum(fn ($row) => ($row['quantity'] ?? 0) <= 100 ? $row['quantity'] : 0),
            'cartBlocked' => $cartRows->isEmpty() || $cartRows->contains('invalid', true),
            'orderRows' => $orderRows, 'activeCount' => $this->activeOrders()->count(),
            'payableCount' => $this->payableOrders($this->activeOrders())->count(),
            'confirmationOrder' => $confirmationOrder, 'confirmationEligible' => (bool) $confirmationEligible,
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink" data-cashier-page>
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-5 px-4 py-6 pb-28 sm:px-6 lg:pb-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><p class="text-sm font-semibold text-pos-link">Kasir</p><h1 class="mt-1 text-2xl font-bold sm:text-3xl">Penjualan dan pembayaran</h1></div>
            <p class="max-w-md text-sm text-pos-muted">Kirim penjualan, tunggu stok dicadangkan, lalu catat pembayaran tunai sebelum pesanan disiapkan.</p>
        </div>
        <div role="status" aria-live="polite" aria-atomic="true" @class(['rounded-xl border border-pos-border bg-pos-soft p-4 text-sm', 'hidden' => $message === ''])>{{ $message }}</div>
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Ruang kerja kasir" data-cashier-tabs>
            @foreach(['sale' => 'Penjualan baru', 'orders' => 'Pesanan aktif'] as $tab => $label)
                <button type="button" id="cashier-{{ $tab }}-tab" wire:key="cashier-tab-{{ $tab }}" role="tab" aria-controls="cashier-{{ $tab }}-panel" aria-selected="{{ $workspace === $tab ? 'true' : 'false' }}" tabindex="{{ $workspace === $tab ? '0' : '-1' }}" wire:click="setWorkspace('{{ $tab }}')" wire:loading.attr="disabled" wire:target="submit,pay,cancel" @class(['min-h-11 rounded-xl px-4 py-3 text-sm font-semibold', 'bg-pos-action text-pos-action-foreground' => $workspace === $tab, 'border border-pos-border bg-pos-surface hover:bg-pos-soft' => $workspace !== $tab])>{{ $label }} @if($tab === 'orders')<span class="ml-1 tabular-nums">({{ $activeCount }})</span><span class="ml-2 text-xs">{{ $payableCount }} perlu pembayaran</span>@endif</button>
            @endforeach
        </div>
        <section id="cashier-sale-panel" role="tabpanel" aria-labelledby="cashier-sale-tab" @if($workspace !== 'sale') hidden @endif>
            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
                <section class="min-w-0 space-y-4" aria-labelledby="cashier-menu-heading">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="cashier-menu-heading" class="text-lg font-bold">Pilih menu</h2><p class="text-sm text-pos-muted">{{ $products->count() }} menu tersedia</p></div>
                    <flux:input wire:model.live.debounce.300ms="search" label="Cari menu" placeholder="Ketik nama menu…" type="search" class="min-h-11" wire:loading.attr="disabled" wire:target="submit" />
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Kategori menu">
                        @foreach(collect([['id' => '', 'name' => 'Semua']])->concat($categories->map(fn ($category) => ['id' => (string) $category->id, 'name' => $category->name])) as $category)
                            <button type="button" wire:key="cashier-category-{{ $category['id'] }}" wire:click="$set('categoryId', '{{ $category['id'] }}')" wire:loading.attr="disabled" wire:target="submit" aria-pressed="{{ $categoryId === $category['id'] ? 'true' : 'false' }}" @class(['min-h-11 max-w-full break-words rounded-full border px-4 py-2 text-sm font-medium', 'border-pos-action bg-pos-action text-pos-action-foreground' => $categoryId === $category['id'], 'border-pos-border bg-pos-surface hover:bg-pos-soft' => $categoryId !== $category['id']])>{{ $category['name'] }}</button>
                        @endforeach
                    </div>
                    <p wire:loading.delay wire:target="search,categoryId,resetFilters" role="status" class="text-sm text-pos-muted">Mencari menu…</p>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @forelse($products as $product)
                            <article wire:key="cashier-product-{{ $product->id }}" class="min-w-0 space-y-3 rounded-2xl border border-pos-border bg-pos-surface p-4 shadow-sm">
                                <div class="flex items-start gap-3"><x-customer-product-image :path="$product->image_url" :show-label="false" wire:key="cashier-image-{{ $product->id }}-{{ md5((string) $product->image_url) }}" class="size-16 rounded-xl" /><div class="min-w-0"><h3 class="break-words font-semibold">{{ $product->name }}</h3><p class="mt-1 break-words text-sm font-semibold text-pos-link"><x-cashier-money :hundredths="\App\Domain\Catalog\ProductAvailability::hundredths($product->active_price)" /></p><p class="mt-1 text-xs text-pos-muted">Tersedia: {{ $availableQuantities[$product->id] }}</p></div></div>
                                <x-cashier-quantity :product-id="$product->id" :name="$product->name" :quantity="$quantities[$product->id] ?? 0" :limit="min(100, $availableQuantities[$product->id])" context="menu" />
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-pos-border-strong bg-pos-surface p-6 sm:col-span-2 xl:col-span-3"><h3 class="font-semibold">{{ $menuCount === 0 ? 'Belum ada menu tersedia' : 'Menu tidak ditemukan' }}</h3><p class="mt-2 text-sm text-pos-muted">{{ $menuCount === 0 ? 'Menu memerlukan resep dan stok bahan yang cukup. Hubungi manajer untuk memeriksa menu.' : 'Coba nama lain atau hapus filter kategori.' }}</p>@if($menuCount > 0)<flux:button wire:click="resetFilters" class="mt-4 min-h-11">Reset filter</flux:button>@endif</div>
                        @endforelse
                    </div>
                </section>
                <aside class="cashier-desktop-cart hidden min-w-0 overflow-hidden rounded-2xl border border-pos-border bg-pos-surface shadow-sm lg:sticky lg:block" aria-label="Keranjang penjualan"><x-cashier-cart context="desktop" :rows="$cartRows" :total="$cartTotal" :count="$cartCount" :blocked="$cartBlocked" :order-type="$orderType" /></aside>
            </div>
            <div class="fixed inset-x-0 bottom-0 z-20 border-t border-pos-border bg-pos-surface p-3 pb-[max(.75rem,env(safe-area-inset-bottom))] lg:hidden"><flux:button variant="primary" class="min-h-12 w-full whitespace-normal" wire:click="$set('showCart', true)" data-cashier-open="cart"><span>{{ $cartRows->count() }} menu · {{ $cartCount }} produk</span><span class="mx-2">·</span><span>Lihat keranjang — <x-cashier-money :hundredths="$cartTotal" /></span></flux:button></div>
        </section>
        <section id="cashier-orders-panel" role="tabpanel" aria-labelledby="cashier-orders-tab" @if($workspace !== 'orders') hidden @endif class="space-y-4 rounded-2xl border border-pos-border bg-pos-surface p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="cashier-orders-heading" tabindex="-1" class="text-xl font-bold">Pesanan aktif</h2><flux:button wire:click="setWorkspace('sale')" class="min-h-11">Kembali ke penjualan baru</flux:button></div>
            <p class="text-sm text-pos-muted">Penjualan kasir hari ini dan semua pesanan pelanggan yang masih aktif.</p>
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem]"><flux:input wire:model.live.debounce.300ms="orderSearch" type="search" label="Cari pesanan" placeholder="Nomor pesanan atau nama pelanggan…" class="min-h-11" /><flux:select wire:model.live="orderFilter" label="Tampilkan pesanan" class="min-h-11"><option value="all">Semua</option><option value="payable">Perlu pembayaran</option></flux:select></div>
            <p wire:loading.delay wire:target="orderSearch,orderFilter,refreshOrders,previousPage,nextPage" role="status" class="text-sm text-pos-muted">Memuat pesanan…</p>
            @if($orderRows->isEmpty())
                <div class="rounded-xl border border-dashed border-pos-border-strong p-6"><h3 class="font-semibold">{{ $activeCount === 0 ? 'Tidak ada pesanan aktif.' : 'Tidak ada pesanan yang cocok.' }}</h3><p class="mt-2 text-sm text-pos-muted">{{ $activeCount === 0 ? 'Penjualan baru akan muncul di sini setelah dikirim.' : 'Ubah pencarian atau filter pembayaran.' }}</p>@if($orderSearch !== '' || $orderFilter !== 'all')<flux:button wire:click="resetOrderFilters" class="mt-4 min-h-11">Reset filter</flux:button>@endif</div>
            @else
                <div class="hidden lg:block"><table class="w-full table-fixed text-left text-sm"><caption class="sr-only">Pesanan aktif dan tindakan kasir</caption><thead><tr class="border-b border-pos-border text-pos-muted"><th scope="col" class="w-[30%] py-3 pr-3">Pesanan</th><th scope="col" class="w-[28%] py-3 pr-3">Status</th><th scope="col" class="w-[18%] py-3 pr-3">Total</th><th scope="col" class="w-[24%] py-3">Tindakan</th></tr></thead><tbody>
                    @foreach($orderRows as $order)<tr wire:key="cashier-order-desktop-{{ $order->id }}" data-cashier-order="{{ $order->id }}" @class(['border-b border-pos-border align-top', 'bg-pos-soft' => $submittedOrderId === $order->id])><td class="py-4 pr-3"><x-cashier-order-identity :order="$order" :highlighted="$submittedOrderId === $order->id" /></td><td class="py-4 pr-3"><x-cashier-order-status :order="$order" /></td><td class="break-words py-4 pr-3 font-semibold"><x-cashier-money :hundredths="\App\Domain\Catalog\ProductAvailability::hundredths($order->total_amount)" /></td><td class="py-4"><x-cashier-order-actions :order="$order" context="desktop" /></td></tr>@endforeach
                </tbody></table></div>
                <div class="space-y-3 lg:hidden">@foreach($orderRows as $order)<article wire:key="cashier-order-mobile-{{ $order->id }}" data-cashier-order="{{ $order->id }}" @class(['min-w-0 space-y-4 rounded-xl border border-pos-border p-4', 'bg-pos-soft' => $submittedOrderId === $order->id])><x-cashier-order-identity :order="$order" :highlighted="$submittedOrderId === $order->id" /><x-cashier-order-status :order="$order" /><p class="break-words font-semibold">Total <x-cashier-money :hundredths="\App\Domain\Catalog\ProductAvailability::hundredths($order->total_amount)" /></p><x-cashier-order-actions :order="$order" context="mobile" /></article>@endforeach</div>
            @endif
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-pos-border pt-4"><p role="status" class="text-sm text-pos-muted">Menampilkan {{ $orderRows->firstItem() ?? 0 }} sampai {{ $orderRows->lastItem() ?? 0 }} dari {{ $orderRows->total() }} pesanan</p><nav aria-label="Halaman pesanan" class="flex gap-2"><flux:button wire:click="previousPage('ordersPage')" wire:loading.attr="disabled" :disabled="$orderRows->onFirstPage()" class="min-h-11">Sebelumnya</flux:button><flux:button wire:click="nextPage('ordersPage')" wire:loading.attr="disabled" :disabled="! $orderRows->hasMorePages()" class="min-h-11">Berikutnya</flux:button></nav></div>
        </section>
    </main>
    <flux:modal name="cashier-cart" wire:model.self="showCart" :dismissible="false" :closable="false" data-cashier-cart class="cashier-sheet border border-pos-border bg-pos-surface! p-0! text-pos-ink"><x-cashier-cart context="mobile" :rows="$cartRows" :total="$cartTotal" :count="$cartCount" :blocked="$cartBlocked" :order-type="$orderType" /></flux:modal>
    <flux:modal name="cashier-confirmation" wire:model.self="showConfirmation" :dismissible="false" :closable="false" data-cashier-confirmation class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-lg overflow-hidden rounded-2xl border border-pos-border bg-pos-surface! p-0! text-pos-ink">
        <form wire:submit="{{ $confirmationAction === 'pay' ? 'pay' : 'cancel' }}({{ $confirmationOrderId ?? 0 }})" class="flex max-h-[calc(100dvh-2rem)] flex-col" data-cashier-confirmation-form>
            <div class="shrink-0 border-b border-pos-border p-5"><h2 id="cashier-confirmation-heading" tabindex="-1" class="text-xl font-bold">{{ $confirmationAction === 'pay' ? 'Catat pembayaran tunai?' : 'Batalkan pesanan?' }}</h2><p id="cashier-confirmation-description" class="mt-2 break-all text-sm text-pos-muted">{{ $confirmationOrderNumber }}</p></div>
            <div class="min-h-0 space-y-4 overflow-y-auto p-5">
                @error('confirmation')<p role="alert" tabindex="-1" data-cashier-confirmation-error class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-3 text-sm text-pos-danger">{{ $message }}</p>@enderror
                @if($confirmationOrder)
                    <x-cashier-order-identity :order="$confirmationOrder" /><x-cashier-order-status :order="$confirmationOrder" />
                    @if($confirmationAction === 'pay')
                        <ul class="space-y-3" aria-label="Rincian pembayaran">@foreach($confirmationOrder->items as $item)<li wire:key="cashier-confirm-item-{{ $item->id }}" class="flex items-start justify-between gap-3 text-sm"><span class="min-w-0 break-words">{{ $item->quantity }} × {{ $item->product?->name ?? 'Produk tidak tersedia' }}<span class="mt-1 block text-xs text-pos-muted"><x-cashier-money :hundredths="\App\Domain\Catalog\ProductAvailability::hundredths($item->unit_price)" /> / produk</span></span><span class="shrink-0 font-medium"><x-cashier-money :hundredths="\App\Domain\Catalog\ProductAvailability::hundredths($item->subtotal)" /></span></li>@endforeach</ul>
                        <div class="flex flex-wrap justify-between gap-2 border-t border-pos-border pt-3 text-lg font-bold"><span>Total tunai</span><span><x-cashier-money :hundredths="\App\Domain\Catalog\ProductAvailability::hundredths($confirmationOrder->total_amount)" /></span></div><p class="text-sm text-pos-muted">Pastikan uang tunai telah diterima sebelum mencatat pembayaran.</p>
                    @else<p class="text-sm text-pos-muted">Pesanan akan dibatalkan. Stok yang dicadangkan akan segera dikembalikan melalui pemrosesan pesanan.</p>@endif
                @endif
                @if(! $confirmationEligible)<p role="status" class="rounded-xl bg-pos-soft p-3 text-sm">{{ $confirmationOrder ? 'Status pesanan berubah atau tindakan ini belum tersedia. Tutup dialog untuk melihat daftar terbaru.' : 'Pesanan tidak ditemukan. Tutup dialog untuk melihat daftar terbaru.' }}</p>@endif
            </div>
            <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-pos-border p-5"><flux:button type="button" wire:click="closeConfirmation" wire:loading.attr="disabled" wire:target="pay,cancel" class="min-h-11">Kembali</flux:button><flux:button type="submit" :variant="$confirmationAction === 'pay' ? 'primary' : 'danger'" :disabled="! $confirmationEligible" wire:loading.attr="disabled" wire:target="pay,cancel" wire:key="cashier-confirm-{{ $confirmationOrderId }}-{{ $confirmationAction }}-{{ $confirmationEligible ? 'ready' : 'blocked' }}" class="min-h-11"><span wire:loading.remove wire:target="pay,cancel">{{ $confirmationAction === 'pay' ? 'Catat tunai' : 'Ya, batalkan' }}</span><span wire:loading wire:target="pay,cancel">Menyimpan…</span></flux:button></div>
        </form>
    </flux:modal>
</div>
