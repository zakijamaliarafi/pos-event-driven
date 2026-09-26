<?php

use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $message = '';

    #[On('order-view-changed')]
    public function refreshOrders(): void {}

    public function serve(int $orderId, TransitionOrder $orders): void
    {
        abort_unless(auth()->user()?->hasRole('waiter'), 403);
        $order = $orders->complete($orderId);
        $this->message = $order->status === 'completed' ? 'Order completed.' : 'Order served. The cashier can now record payment.';
    }

    public function verify(int $orderId, TransitionOrder $orders): void
    {
        abort_unless(auth()->user()?->hasRole('waiter'), 403);
        $orders->verify($orderId);
        $this->message = 'Order verified. Stock consumption is processing before the kitchen receives it.';
    }

    public function reject(int $orderId, TransitionOrder $orders): void
    {
        abort_unless(auth()->user()?->hasRole('waiter'), 403);
        $orders->reject($orderId);
        $this->message = 'Order rejected. Reserved stock will be released shortly.';
    }

    public function with(): array
    {
        $verificationIds = DB::table('order_read_models')->where('status', 'awaiting_verification')->orderBy('created_at')->pluck('order_id');
        $readyIds = DB::table('order_read_models')->where('status', 'ready')->orderBy('updated_at')->pluck('order_id');

        return [
            'verificationOrders' => Order::with('items.product')->whereIn('id', $verificationIds)->orderBy('created_at')->get(),
            'readyOrders' => Order::with('items.product')->whereIn('id', $readyIds)->orderBy('updated_at')->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-slate-50 text-slate-900">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-blue-700">Fulfillment</p><h1 class="mt-1 text-3xl font-bold">Waiter orders</h1></div>
        @if($message)<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-blue-900">{{ $message }}</div>@endif
        @error('order')<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">{{ $message }}</div>@enderror
        <section wire:poll.2s>
            <h2 class="mb-4 text-xl font-semibold">Awaiting verification</h2>
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($verificationOrders as $order)
                <article wire:key="verify-{{ $order->id }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="font-bold">{{ $order->order_number }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ $order->customer_name }} · Table {{ $order->table_number }}</p>
                    <ul class="my-5 space-y-2 border-y border-slate-100 py-4 text-sm">@foreach($order->items as $item)<li class="flex justify-between gap-3"><span>{{ $item->product?->name ?? 'Product' }}</span><strong>× {{ $item->quantity }}</strong></li>@endforeach</ul>
                    <div class="flex gap-2"><button wire:click="verify({{ $order->id }})" wire:confirm="Verify this order and send it to the kitchen?" class="flex-1 rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white hover:bg-blue-800">Verify</button><button wire:click="reject({{ $order->id }})" wire:confirm="Reject this order?" class="rounded-lg border border-red-300 px-4 py-2 font-semibold text-red-700">Reject</button></div>
                </article>
            @empty
                <p class="text-slate-500">No customer orders need verification.</p>
            @endforelse
            </div>
        </section>
        <section wire:poll.2s>
            <h2 class="mb-4 text-xl font-semibold">Ready to serve</h2>
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($readyOrders as $order)
                <article wire:key="ready-{{ $order->id }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-bold">{{ $order->order_number }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $order->customer_name }} · {{ $order->order_type === 'takeaway' ? 'Takeaway' : 'Table '.$order->table_number }}</p>
                    <ul class="my-5 space-y-2 border-y border-slate-100 py-4 text-sm">@foreach($order->items as $item)<li class="flex justify-between gap-3"><span>{{ $item->product?->name ?? 'Product' }}</span><strong>× {{ $item->quantity }}</strong></li>@endforeach</ul>
                    <button wire:click="serve({{ $order->id }})" class="w-full rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white hover:bg-blue-800">Mark served</button>
                </article>
            @empty
                <p class="text-slate-500">No orders are ready right now.</p>
            @endforelse
            </div>
        </section>
    </main>
</div>
