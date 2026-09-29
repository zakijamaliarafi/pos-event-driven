<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $range = 'today';

    #[On('order-view-changed')]
    public function refreshMetrics(): void {}

    public function with(): array
    {
        $from = match ($this->range) {
            'week' => today()->startOfWeek(),
            'month' => today()->startOfMonth(),
            default => today(),
        };
        $to = now();
        $paid = DB::table('order_read_models')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to]);
        $topProducts = DB::table('order_items')
            ->join('order_read_models', 'order_items.order_id', '=', 'order_read_models.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('order_read_models.paid_at', [$from, $to])
            ->select('products.name', DB::raw('SUM(order_items.quantity) as units'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('units')->limit(5)->get();

        return [
            'revenue' => (float) (clone $paid)->sum('total_amount'),
            'orderCount' => (clone $paid)->count(),
            'readyCount' => DB::table('order_read_models')->where('status', 'ready')->count(),
            'topProducts' => $topProducts,
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-8 px-5 py-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="text-sm font-semibold uppercase tracking-widest text-pos-link">Laporan</p><h1 class="mt-1 text-3xl font-bold">Dasbor penjualan</h1></div>
            <label class="text-sm font-medium text-pos-muted">Periode<select wire:model.live="range" class="ml-2 rounded-lg border border-pos-border-strong px-3 py-2 text-pos-ink"><option value="today">Hari ini</option><option value="week">Minggu ini</option><option value="month">Bulan ini</option></select></label>
        </div>
        <div wire:poll.10s class="grid gap-5 md:grid-cols-3">
            <div class="rounded-2xl border border-pos-border bg-pos-surface p-6 shadow-sm"><p class="text-sm text-pos-muted">Pendapatan tunai</p><p class="mt-3 text-3xl font-bold text-pos-link">{{ config('pos.currency') }} {{ number_format($revenue, 0) }}</p></div>
            <div class="rounded-2xl border border-pos-border bg-pos-surface p-6 shadow-sm"><p class="text-sm text-pos-muted">Pesanan dibayar</p><p class="mt-3 text-3xl font-bold">{{ number_format($orderCount) }}</p></div>
            <div class="rounded-2xl border border-pos-border bg-pos-surface p-6 shadow-sm"><p class="text-sm text-pos-muted">Siap disajikan</p><p class="mt-3 text-3xl font-bold">{{ number_format($readyCount) }}</p></div>
        </div>
        <section class="rounded-2xl border border-pos-border bg-pos-surface p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Produk terlaris</h2>
            <div class="mt-5 space-y-3">
                @forelse($topProducts as $product)
                    <div class="flex justify-between border-b border-pos-border pb-3 text-sm"><span>{{ $product->name }}</span><strong>{{ $product->units }} terjual</strong></div>
                @empty<p class="text-sm text-pos-muted">Belum ada penjualan yang dibayar pada periode ini.</p>@endforelse
            </div>
        </section>
    </main>
</div>
