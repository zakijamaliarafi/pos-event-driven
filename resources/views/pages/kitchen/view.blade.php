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
        $this->message = __('orders.messages.preparation_started');
    }

    public function ready(int $orderId, TransitionOrder $orders): void
    {
        $orders->markReady($orderId);
        $this->message = __('orders.messages.marked_ready');
    }

    public function with(): array
    {
        $ids = DB::table('order_read_models')->whereIn('status', ['pending', 'preparing'])->orderBy('created_at')->pluck('order_id');

        return ['orders' => Order::with('items.product')->whereIn('id', $ids)->orderBy('created_at')->get()];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-pos-link">Layanan</p><h1 class="mt-1 text-3xl font-bold">Antrean dapur</h1></div>
        @if($message)<div class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ $message }}</div>@endif
        @error('order')<div class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $message }}</div>@enderror
        <div wire:poll.2s class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($orders as $order)
                <article class="rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3"><div><h2 class="font-bold">{{ $order->order_number }}</h2><p class="text-sm text-pos-muted">{{ $order->order_type === 'takeaway' ? __('orders.type.takeaway') : 'Meja '.$order->table_number }}</p></div><span class="rounded-full bg-pos-soft px-3 py-1 text-xs font-semibold text-pos-link">{{ __('orders.status.'.$order->status) }}</span></div>
                    <ul class="my-5 space-y-2 border-y border-pos-border py-4 text-sm">@foreach($order->items as $item)<li class="flex justify-between gap-3"><span>{{ $item->product?->name ?? 'Produk' }}</span><strong>× {{ $item->quantity }}</strong></li>@endforeach</ul>
                    @if($order->status === 'pending')<button wire:click="start({{ $order->id }})" class="w-full rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground hover:bg-pos-action-hover">Mulai siapkan</button>@endif
                    @if($order->status === 'preparing')<button wire:click="ready({{ $order->id }})" class="w-full rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground hover:bg-pos-action-hover">Tandai siap</button>@endif
                </article>
            @empty
                <p class="text-pos-muted">Tidak ada pesanan yang menunggu penyiapan.</p>
            @endforelse
        </div>
    </main>
</div>
