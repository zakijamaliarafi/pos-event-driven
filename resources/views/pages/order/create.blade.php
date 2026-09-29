<?php

use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $customerName = '';
    public string $customerPhone = '';
    public string $orderType = 'dine_in';
    public ?int $tableNumber = null;
    public array $quantities = [];
    public string $message = '';

    #[On('order-view-changed')]
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
            'order_type' => $this->orderType,
            'table_number' => $this->tableNumber,
            'customer_name' => $this->customerName,
            'customer_phone' => $this->customerPhone,
            'cart' => $cart,
        ], cashierId: (int) Auth::id());

        $this->quantities = [];
        $this->message = __('orders.messages.cashier_submitted', ['number' => $order->order_number]);
    }

    public function pay(int $orderId, RecordCashPayment $payments): void
    {
        abort_unless(Auth::user()?->hasRole('cashier'), 403);
        $order = $payments->handle($orderId, (int) Auth::id(), "cash-order-{$orderId}");
        $this->message = $order->device_id === null
            ? __('orders.messages.cash_recorded_prepaid', ['number' => $order->order_number])
            : __('orders.messages.cash_recorded_customer', ['number' => $order->order_number]);
    }

    public function cancel(int $orderId, TransitionOrder $orders): void
    {
        $order = $orders->cancel($orderId);
        $this->message = __('orders.messages.cashier_cancelled', ['number' => $order->order_number]);
    }

    public function with(): array
    {
        return [
            'products' => Product::query()->with('discounts')->where('is_available', true)->orderBy('name')->get(),
            'activeOrders' => Order::query()
                ->whereIn('status', ['awaiting_inventory', 'awaiting_verification', 'verifying', 'waiting_payment', 'pending', 'preparing', 'ready', 'awaiting_payment'])
                ->where(fn ($query) => $query->whereDate('created_at', today())->orWhereNotNull('device_id'))
                ->latest()->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-8 px-5 py-8">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-pos-link">Kasir</p>
            <h1 class="mt-1 text-3xl font-bold">Penjualan baru</h1>
            <p class="mt-2 text-pos-muted">Kirim pesanan, lalu catat pembayaran tunai setelah stok dicadangkan.</p>
        </div>
        @if($message)<div class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ $message }}</div>@endif
        @error('cart')<div class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $message }}</div>@enderror
        @error('payment')<div class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $message }}</div>@enderror
        @error('order')<div class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $message }}</div>@enderror

        <form wire:submit="submit" class="grid gap-8 lg:grid-cols-[1fr_20rem]">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($products as $product)
                    <div class="rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm">
                        <h2 class="font-semibold">{{ $product->name }}</h2>
                        <p class="mt-1 text-sm font-semibold text-pos-link">{{ config('pos.currency') }} {{ number_format($product->active_price, 0) }}</p>
                        <p class="mt-1 text-xs text-pos-muted">Tersedia: {{ max(0, $product->current_stock - $product->reserved_stock) }}</p>
                        <label class="mt-4 block text-sm text-pos-muted">Jumlah<input type="number" min="0" max="100" wire:model.number="quantities.{{ $product->id }}" class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2"></label>
                    </div>
                @endforeach
            </section>
            <aside class="h-fit space-y-4 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Detail penjualan</h2>
                <label class="block text-sm font-medium">Jenis pesanan<select wire:model.live="orderType" class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2"><option value="dine_in">Makan di tempat</option><option value="takeaway">Bawa pulang</option></select></label>
                @if($orderType === 'dine_in')<label class="block text-sm font-medium">Nomor meja<input type="number" min="1" wire:model="tableNumber" class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2"></label>@endif
                @error('table_number')<p class="text-sm text-pos-danger">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium">Nama pelanggan<input type="text" wire:model="customerName" class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2"></label>
                @error('customer_name')<p class="text-sm text-pos-danger">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium">Nomor telepon (opsional)<input type="tel" wire:model="customerPhone" class="mt-1 w-full rounded-lg border border-pos-border-strong px-3 py-2"></label>
                <button type="submit" class="w-full rounded-lg bg-pos-action px-4 py-3 font-semibold text-pos-action-foreground hover:bg-pos-action-hover" wire:loading.attr="disabled">Kirim penjualan</button>
            </aside>
        </form>

        <section wire:poll.2s class="rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Pesanan aktif</h2>
            <div class="space-y-3">
                @forelse($activeOrders as $order)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-pos-border pb-3 text-sm">
                        <div><strong>{{ $order->order_number }}</strong><span class="ml-2 text-pos-muted">{{ $order->customer_name }} · {{ $order->order_type === 'takeaway' ? __('orders.type.takeaway') : 'Meja '.$order->table_number }}</span></div>
                        <span class="text-pos-link">{{ __('orders.status.'.$order->status) }}</span>
                        <span class="font-medium">{{ config('pos.currency') }} {{ number_format($order->total_amount, 0) }}</span>
                        @if(in_array($order->status, ['waiting_payment', 'awaiting_payment'], true))<button wire:click="pay({{ $order->id }})" wire:confirm="Catat pembayaran tunai untuk pesanan ini?" class="rounded-lg bg-pos-action px-3 py-2 font-semibold text-pos-action-foreground hover:bg-pos-action-hover">Catat tunai</button>@endif
                        @if(in_array($order->status, ['awaiting_inventory', 'waiting_payment', 'awaiting_verification'], true))<button wire:click="cancel({{ $order->id }})" wire:confirm="Batalkan pesanan ini?" class="rounded-lg border border-pos-border-strong px-3 py-2 text-pos-ink hover:border-pos-danger-border hover:text-pos-danger">Batalkan</button>@endif
                    </div>
                @empty
                    <p class="text-sm text-pos-muted">Tidak ada pesanan aktif.</p>
                @endforelse
            </div>
        </section>
    </main>
</div>
