<?php

use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $deviceId = '';
    public string $customerName = '';
    public string $customerPhone = '';
    public int $tableNumber = 1;
    public array $quantities = [];
    public string $message = '';

    public function mount(): void
    {
        $stored = request()->cookie('pos_device');
        $this->deviceId = is_string($stored) && Str::isUuid($stored) ? $stored : (string) Str::uuid();
        Cookie::queue('pos_device', $this->deviceId, 1440);
    }

    #[On('customer-order-view-changed')]
    public function refreshOrders(): void {}

    public function submit(SubmitOrder $orders): void
    {
        $cart = collect($this->quantities)
            ->filter(fn ($qty) => is_numeric($qty) && (int) $qty > 0)
            ->map(fn ($qty, $id): array => ['id' => (int) $id, 'qty' => (int) $qty])
            ->values()->all();

        if ($cart === []) {
            throw ValidationException::withMessages(['cart' => __('orders.errors.empty_cart')]);
        }

        $order = $orders->handle([
            'order_type' => 'dine_in',
            'table_number' => $this->tableNumber,
            'customer_name' => $this->customerName,
            'customer_phone' => $this->customerPhone,
            'cart' => $cart,
        ], deviceId: $this->deviceId);

        $this->quantities = [];
        $this->message = __('orders.messages.customer_submitted', ['number' => $order->order_number]);
        $this->dispatch('customer-order-submitted', orderId: $order->id);
    }

    public function cancel(int $orderId, TransitionOrder $orders): void
    {
        Order::query()->whereKey($orderId)->where('device_id', $this->deviceId)->firstOrFail();
        $orders->cancel($orderId);
        $this->message = __('orders.messages.customer_cancelled');
    }

    public function with(): array
    {
        return [
            'products' => Product::query()->with('discounts')->where('is_available', true)->orderBy('name')->get(),
            'orders' => Order::query()->where('device_id', $this->deviceId)->latest()->limit(10)->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-8 px-5 py-8">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-pos-link">Pesan mandiri</p>
            <h1 class="mt-1 text-3xl font-bold">Buat pesanan</h1>
            <p class="mt-2 text-pos-muted">Pilih menu. Pramusaji akan memverifikasi pesanan, lalu Anda dapat membayar tunai di kasir setelah makan.</p>
        </div>

        @if($message)<div class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ $message }}</div>@endif
        @error('cart')<div class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $message }}</div>@enderror
        @error('order')<div class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $message }}</div>@enderror

        <form wire:submit="submit" class="grid gap-8 lg:grid-cols-[1fr_20rem]">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @forelse($products as $product)
                    <div class="rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="font-semibold">{{ $product->name }}</h2>
                            <span class="text-sm font-semibold text-pos-link">{{ config('pos.currency') }} {{ number_format($product->active_price, 0) }}</span>
                        </div>
                        <p class="mt-2 text-xs text-pos-muted">Tersedia: {{ max(0, $product->current_stock - $product->reserved_stock) }}</p>
                        <label class="mt-4 block text-sm text-pos-muted" for="quantity-{{ $product->id }}">Jumlah</label>
                        <input id="quantity-{{ $product->id }}" type="number" min="0" max="100" wire:model.number="quantities.{{ $product->id }}" class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2 focus:border-pos-focus focus:ring-pos-focus">
                    </div>
                @empty
                    <p class="text-pos-muted">Belum ada produk yang tersedia.</p>
                @endforelse
            </section>
            <aside class="h-fit space-y-4 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Detail pesanan</h2>
                <label class="block text-sm font-medium">Nomor meja<input type="number" min="1" wire:model="tableNumber" class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2"></label>
                @error('table_number')<p class="text-sm text-pos-danger">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium">Nama Anda<input type="text" wire:model="customerName" class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2"></label>
                @error('customer_name')<p class="text-sm text-pos-danger">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium">Nomor telepon (opsional)<input type="tel" wire:model="customerPhone" class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2"></label>
                <button type="submit" class="w-full rounded-lg bg-pos-action px-4 py-3 font-semibold text-pos-action-foreground hover:bg-pos-action-hover" wire:loading.attr="disabled">Kirim pesanan</button>
            </aside>
        </form>

        <section wire:poll.3s class="rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Pesanan terbaru Anda</h2>
            <div class="space-y-3">
                @forelse($orders as $order)
                    <div data-pos-order-id="{{ $order->id }}" class="flex flex-wrap justify-between gap-2 border-b border-pos-border pb-3 text-sm">
                        <span class="font-semibold">{{ $order->order_number }}</span>
                        <span class="text-pos-link">{{ __('orders.status.'.$order->status) }}</span>
                        <span>{{ config('pos.currency') }} {{ number_format($order->total_amount, 0) }}</span>
                        @if(in_array($order->status, ['awaiting_inventory', 'awaiting_verification'], true))
                            <button wire:click="cancel({{ $order->id }})" wire:confirm="Batalkan pesanan yang belum dibayar ini?" class="rounded-lg border border-pos-border-strong px-2 py-1 text-pos-ink hover:border-pos-danger-border hover:text-pos-danger">Batalkan</button>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-pos-muted">Pesanan Anda akan tampil di sini.</p>
                @endforelse
            </div>
        </section>
    </main>
</div>
