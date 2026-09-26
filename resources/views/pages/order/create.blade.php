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
            throw ValidationException::withMessages(['cart' => 'Choose at least one product.']);
        }

        $order = $orders->handle([
            'order_type' => $this->orderType,
            'table_number' => $this->tableNumber,
            'customer_name' => $this->customerName,
            'customer_phone' => $this->customerPhone,
            'cart' => $cart,
        ], cashierId: (int) Auth::id());

        $this->quantities = [];
        $this->message = "Order {$order->order_number} submitted. Confirm cash payment once stock is reserved.";
    }

    public function pay(int $orderId, RecordCashPayment $payments): void
    {
        $order = $payments->handle($orderId, (int) Auth::id(), "cash-order-{$orderId}");
        $this->message = "Cash recorded for {$order->order_number}. The kitchen will receive it shortly.";
    }

    public function cancel(int $orderId, TransitionOrder $orders): void
    {
        $order = $orders->cancel($orderId);
        $this->message = "Order {$order->order_number} cancelled.";
    }

    public function with(): array
    {
        return [
            'products' => Product::query()->with('discounts')->where('is_available', true)->orderBy('name')->get(),
            'activeOrders' => Order::query()->whereDate('created_at', today())->whereIn('status', ['awaiting_inventory', 'waiting_payment', 'pending', 'preparing', 'ready'])->latest()->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-slate-50 text-slate-900">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-8 px-5 py-8">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-blue-700">Cashier</p>
            <h1 class="mt-1 text-3xl font-bold">New sale</h1>
            <p class="mt-2 text-slate-600">Submit the order, then record cash payment when stock is reserved.</p>
        </div>
        @if($message)<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-blue-900">{{ $message }}</div>@endif
        @error('cart')<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">{{ $message }}</div>@enderror
        @error('payment')<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">{{ $message }}</div>@enderror
        @error('order')<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">{{ $message }}</div>@enderror

        <form wire:submit="submit" class="grid gap-8 lg:grid-cols-[1fr_20rem]">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($products as $product)
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="font-semibold">{{ $product->name }}</h2>
                        <p class="mt-1 text-sm font-semibold text-blue-700">{{ config('pos.currency') }} {{ number_format($product->active_price, 0) }}</p>
                        <p class="mt-1 text-xs text-slate-500">Available: {{ max(0, $product->current_stock - $product->reserved_stock) }}</p>
                        <label class="mt-4 block text-sm text-slate-600">Quantity<input type="number" min="0" max="100" wire:model.number="quantities.{{ $product->id }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                    </div>
                @endforeach
            </section>
            <aside class="h-fit space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Sale details</h2>
                <label class="block text-sm font-medium">Order type<select wire:model.live="orderType" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"><option value="dine_in">Dine in</option><option value="takeaway">Takeaway</option></select></label>
                @if($orderType === 'dine_in')<label class="block text-sm font-medium">Table number<input type="number" min="1" wire:model="tableNumber" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>@endif
                @error('table_number')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium">Customer name<input type="text" wire:model="customerName" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                @error('customer_name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium">Phone (optional)<input type="tel" wire:model="customerPhone" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                <button type="submit" class="w-full rounded-lg bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800" wire:loading.attr="disabled">Submit sale</button>
            </aside>
        </form>

        <section wire:poll.2s class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Today's active orders</h2>
            <div class="space-y-3">
                @forelse($activeOrders as $order)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3 text-sm">
                        <div><strong>{{ $order->order_number }}</strong><span class="ml-2 text-slate-500">{{ $order->customer_name }} · {{ $order->order_type === 'takeaway' ? 'Takeaway' : 'Table '.$order->table_number }}</span></div>
                        <span class="capitalize text-blue-700">{{ str_replace('_', ' ', $order->status) }}</span>
                        <span class="font-medium">{{ config('pos.currency') }} {{ number_format($order->total_amount, 0) }}</span>
                        @if($order->status === 'waiting_payment')<button wire:click="pay({{ $order->id }})" wire:confirm="Record cash payment for this order?" class="rounded-lg bg-blue-700 px-3 py-2 font-semibold text-white hover:bg-blue-800">Record cash</button>@endif
                        @if(in_array($order->status, ['awaiting_inventory', 'waiting_payment'], true))<button wire:click="cancel({{ $order->id }})" wire:confirm="Cancel this unpaid order?" class="rounded-lg border border-slate-300 px-3 py-2 text-slate-700 hover:border-red-300 hover:text-red-700">Cancel</button>@endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No active orders today.</p>
                @endforelse
            </div>
        </section>
    </main>
</div>
