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
        $this->message = $order->status === 'completed' ? __('orders.messages.completed') : __('orders.messages.served');
    }

    public function verify(int $orderId, TransitionOrder $orders): void
    {
        abort_unless(auth()->user()?->hasRole('waiter'), 403);
        $orders->verify($orderId);
        $this->message = __('orders.messages.verified');
    }

    public function reject(int $orderId, TransitionOrder $orders): void
    {
        abort_unless(auth()->user()?->hasRole('waiter'), 403);
        $orders->reject($orderId);
        $this->message = __('orders.messages.rejected');
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

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-pos-link">Layanan</p><h1 class="mt-1 text-3xl font-bold">Pesanan pramusaji</h1></div>
        @if($message)<div class="rounded-xl border border-pos-border bg-pos-soft p-4 text-pos-ink">{{ $message }}</div>@endif
        @error('order')<div class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-4 text-pos-danger">{{ $message }}</div>@enderror
        <section wire:poll.2s>
            <h2 class="mb-4 text-xl font-semibold">Menunggu verifikasi</h2>
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($verificationOrders as $order)
                <article wire:key="verify-{{ $order->id }}" class="rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm">
                    <h3 class="font-bold">{{ $order->order_number }}</h3>
                    <p class="mt-1 text-sm text-pos-muted">{{ $order->customer_name }} · Meja {{ $order->table_number }}</p>
                    <ul class="my-5 space-y-2 border-y border-pos-border py-4 text-sm">@foreach($order->items as $item)<li class="flex justify-between gap-3"><span>{{ $item->product?->name ?? 'Produk' }}</span><strong>× {{ $item->quantity }}</strong></li>@endforeach</ul>
                    <div class="flex gap-2"><button wire:click="verify({{ $order->id }})" wire:confirm="Verifikasi pesanan ini dan kirim ke dapur?" class="flex-1 rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground hover:bg-pos-action-hover">Verifikasi</button><button wire:click="reject({{ $order->id }})" wire:confirm="Tolak pesanan ini?" class="rounded-lg border border-pos-danger-border px-4 py-2 font-semibold text-pos-danger">Tolak</button></div>
                </article>
            @empty
                <p class="text-pos-muted">Tidak ada pesanan pelanggan yang perlu diverifikasi.</p>
            @endforelse
            </div>
        </section>
        <section wire:poll.2s>
            <h2 class="mb-4 text-xl font-semibold">Siap disajikan</h2>
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($readyOrders as $order)
                <article wire:key="ready-{{ $order->id }}" class="rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm">
                    <h2 class="font-bold">{{ $order->order_number }}</h2>
                    <p class="mt-1 text-sm text-pos-muted">{{ $order->customer_name }} · {{ $order->order_type === 'takeaway' ? __('orders.type.takeaway') : 'Meja '.$order->table_number }}</p>
                    <ul class="my-5 space-y-2 border-y border-pos-border py-4 text-sm">@foreach($order->items as $item)<li class="flex justify-between gap-3"><span>{{ $item->product?->name ?? 'Produk' }}</span><strong>× {{ $item->quantity }}</strong></li>@endforeach</ul>
                    <button wire:click="serve({{ $order->id }})" class="w-full rounded-lg bg-pos-action px-4 py-2 font-semibold text-pos-action-foreground hover:bg-pos-action-hover">Tandai sudah disajikan</button>
                </article>
            @empty
                <p class="text-pos-muted">Belum ada pesanan yang siap disajikan.</p>
            @endforelse
            </div>
        </section>
    </main>
</div>
