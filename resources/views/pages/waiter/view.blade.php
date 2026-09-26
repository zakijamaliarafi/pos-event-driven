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
        $orders->complete($orderId);
        $this->message = 'Order completed.';
    }

    public function with(): array
    {
        $ids = DB::table('order_read_models')->where('status', 'ready')->orderBy('updated_at')->pluck('order_id');

        return ['orders' => Order::with('items.product')->whereIn('id', $ids)->orderBy('updated_at')->get()];
    }
};
?>

<div class="min-h-screen bg-slate-50 text-slate-900">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-blue-700">Fulfillment</p><h1 class="mt-1 text-3xl font-bold">Ready to serve</h1></div>
        @if($message)<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-blue-900">{{ $message }}</div>@endif
        @error('order')<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">{{ $message }}</div>@enderror
        <div wire:poll.2s class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($orders as $order)
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-bold">{{ $order->order_number }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $order->customer_name }} · {{ $order->order_type === 'takeaway' ? 'Takeaway' : 'Table '.$order->table_number }}</p>
                    <ul class="my-5 space-y-2 border-y border-slate-100 py-4 text-sm">@foreach($order->items as $item)<li class="flex justify-between gap-3"><span>{{ $item->product?->name ?? 'Product' }}</span><strong>× {{ $item->quantity }}</strong></li>@endforeach</ul>
                    <button wire:click="serve({{ $order->id }})" class="w-full rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white hover:bg-blue-800">Mark served</button>
                </article>
            @empty
                <p class="text-slate-500">No orders are ready right now.</p>
            @endforelse
        </div>
    </main>
</div>
