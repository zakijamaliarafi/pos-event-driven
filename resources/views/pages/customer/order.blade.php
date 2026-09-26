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
            throw ValidationException::withMessages(['cart' => 'Choose at least one product.']);
        }

        $order = $orders->handle([
            'order_type' => 'dine_in',
            'table_number' => $this->tableNumber,
            'customer_name' => $this->customerName,
            'customer_phone' => $this->customerPhone,
            'cart' => $cart,
        ], deviceId: $this->deviceId);

        $this->quantities = [];
        $this->message = "Order {$order->order_number} submitted. A waiter will verify it before preparation. Please pay at the cashier after your meal.";
        $this->dispatch('customer-order-submitted', orderId: $order->id);
    }

    public function cancel(int $orderId, TransitionOrder $orders): void
    {
        Order::query()->whereKey($orderId)->where('device_id', $this->deviceId)->firstOrFail();
        $orders->cancel($orderId);
        $this->message = 'Order cancelled. Reserved stock will be released shortly.';
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

<div class="min-h-screen bg-slate-50 text-slate-900">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-8 px-5 py-8">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-blue-700">Self-service</p>
            <h1 class="mt-1 text-3xl font-bold">Place an order</h1>
            <p class="mt-2 text-slate-600">Select products. A waiter will verify your order, and you can pay cash after your meal.</p>
        </div>

        @if($message)<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-blue-900">{{ $message }}</div>@endif
        @error('cart')<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">{{ $message }}</div>@enderror
        @error('order')<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">{{ $message }}</div>@enderror

        <form wire:submit="submit" class="grid gap-8 lg:grid-cols-[1fr_20rem]">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @forelse($products as $product)
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="font-semibold">{{ $product->name }}</h2>
                            <span class="text-sm font-semibold text-blue-700">{{ config('pos.currency') }} {{ number_format($product->active_price, 0) }}</span>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">Available: {{ max(0, $product->current_stock - $product->reserved_stock) }}</p>
                        <label class="mt-4 block text-sm text-slate-600" for="quantity-{{ $product->id }}">Quantity</label>
                        <input id="quantity-{{ $product->id }}" type="number" min="0" max="100" wire:model.number="quantities.{{ $product->id }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-blue-600 focus:ring-blue-600">
                    </div>
                @empty
                    <p class="text-slate-500">No products are available yet.</p>
                @endforelse
            </section>
            <aside class="h-fit space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Order details</h2>
                <label class="block text-sm font-medium">Table number<input type="number" min="1" wire:model="tableNumber" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                @error('table_number')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium">Your name<input type="text" wire:model="customerName" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                @error('customer_name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium">Phone (optional)<input type="tel" wire:model="customerPhone" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                <button type="submit" class="w-full rounded-lg bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800" wire:loading.attr="disabled">Submit order</button>
            </aside>
        </form>

        <section wire:poll.3s class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Your recent orders</h2>
            <div class="space-y-3">
                @forelse($orders as $order)
                    <div data-pos-order-id="{{ $order->id }}" class="flex flex-wrap justify-between gap-2 border-b border-slate-100 pb-3 text-sm">
                        <span class="font-semibold">{{ $order->order_number }}</span>
                        <span class="capitalize text-blue-700">{{ match ($order->status) { 'awaiting_inventory' => 'Checking stock', 'awaiting_verification' => 'Waiting for waiter', 'verifying' => 'Waiter verified', 'pending' => 'Queued for kitchen', 'awaiting_payment' => 'Please pay at cashier', default => str_replace('_', ' ', $order->status) } }}</span>
                        <span>{{ config('pos.currency') }} {{ number_format($order->total_amount, 0) }}</span>
                        @if(in_array($order->status, ['awaiting_inventory', 'awaiting_verification'], true))
                            <button wire:click="cancel({{ $order->id }})" wire:confirm="Cancel this unpaid order?" class="rounded-lg border border-slate-300 px-2 py-1 text-slate-700 hover:border-red-300 hover:text-red-700">Cancel</button>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Your orders will appear here.</p>
                @endforelse
            </div>
        </section>
    </main>
</div>
