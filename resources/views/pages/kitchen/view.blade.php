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

    public function start(int $orderId, TransitionOrder $orders): void
    {
        $orders->startPreparation($orderId);
        $this->message = 'Preparation started.';
    }

    public function ready(int $orderId, TransitionOrder $orders): void
    {
        $orders->markReady($orderId);
        $this->message = 'Order marked ready.';
    }

    public function with(): array
    {
        $ids = DB::table('order_read_models')->whereIn('status', ['pending', 'preparing'])->orderBy('created_at')->pluck('order_id');

        return ['orders' => Order::with('items.product')->whereIn('id', $ids)->orderBy('created_at')->get()];
    }
};
?>

<div class="min-h-screen bg-slate-50 text-slate-900">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-blue-700">Fulfillment</p><h1 class="mt-1 text-3xl font-bold">Kitchen queue</h1></div>
        @if($message)<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-blue-900">{{ $message }}</div>@endif
        @error('order')<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">{{ $message }}</div>@enderror
        <div wire:poll.2s class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($orders as $order)
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3"><div><h2 class="font-bold">{{ $order->order_number }}</h2><p class="text-sm text-slate-500">{{ $order->order_type === 'takeaway' ? 'Takeaway' : 'Table '.$order->table_number }}</p></div><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold capitalize text-blue-700">{{ $order->status }}</span></div>
                    <ul class="my-5 space-y-2 border-y border-slate-100 py-4 text-sm">@foreach($order->items as $item)<li class="flex justify-between gap-3"><span>{{ $item->product?->name ?? 'Product' }}</span><strong>× {{ $item->quantity }}</strong></li>@endforeach</ul>
                    @if($order->status === 'pending')<button wire:click="start({{ $order->id }})" class="w-full rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white hover:bg-blue-800">Start preparation</button>@endif
                    @if($order->status === 'preparing')<button wire:click="ready({{ $order->id }})" class="w-full rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white hover:bg-blue-800">Mark ready</button>@endif
                </article>
            @empty
                <p class="text-slate-500">No orders are waiting for preparation.</p>
            @endforelse
        </div>
    </main>
</div>
