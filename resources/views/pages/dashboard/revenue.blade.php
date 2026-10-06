<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
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
        $metrics = (clone $paid)->selectRaw('COALESCE(SUM(total_amount), 0) as revenue, COUNT(*) as order_count')->first();
        $hourly = ! in_array($this->range, ['week', 'month'], true);
        $bucketFormat = $hourly ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d';
        $counts = (clone $paid)
            ->selectRaw('DATE_FORMAT(paid_at, ?) as bucket, COUNT(*) as orders', [$bucketFormat])
            ->groupBy('bucket')
            ->pluck('orders', 'bucket');
        $trend = [];
        $end = $hourly ? today()->endOfDay() : today();

        for ($bucket = $from; $bucket <= $end; $bucket = $hourly ? $bucket->addHour() : $bucket->addDay()) {
            $key = $bucket->format($hourly ? 'Y-m-d H:00:00' : 'Y-m-d');
            $trend[] = [
                'key' => $key,
                'label' => $bucket->format($hourly ? 'H:00' : 'd/m'),
                'count' => (int) ($counts[$key] ?? 0),
            ];
        }

        $peakCount = max(array_column($trend, 'count'));
        $tickStep = max(1, (int) ceil($peakCount / 4));
        $chartMax = max(1, (int) ceil($peakCount / $tickStep) * $tickStep);
        $trendCharts = [];

        foreach (['mobile' => [320, 240, 6], 'desktop' => [640, 280, 8]] as $variant => [$width, $height, $labelLimit]) {
            $left = max(40, strlen((string) $chartMax) * 8 + 12);
            $right = $width - 24;
            $bottom = $height - 32;
            $ticks = [];
            $points = [];
            $labels = [];

            for ($count = 0; $count <= $chartMax; $count += $tickStep) {
                $ticks[] = ['count' => $count, 'y' => $bottom - ($count / $chartMax * ($bottom - 16))];
            }

            foreach ($trend as $index => $point) {
                $points[] = $point + [
                    'x' => count($trend) === 1 ? ($left + $right) / 2 : $left + ($index / (count($trend) - 1) * ($right - $left)),
                    'y' => $bottom - ($point['count'] / $chartMax * ($bottom - 16)),
                ];
            }

            $labelCount = min($labelLimit, count($points));

            for ($index = 0; $index < $labelCount; $index++) {
                $pointIndex = $labelCount === 1 ? 0 : (int) round($index / ($labelCount - 1) * (count($points) - 1));
                $labels[] = $points[$pointIndex];
            }

            $trendCharts[$variant] = [
                'width' => $width,
                'height' => $height,
                'left' => $left,
                'right' => $right,
                'bottom' => $bottom,
                'ticks' => $ticks,
                'points' => $points,
                'labels' => $labels,
                'polyline' => implode(' ', array_map(fn (array $point): string => $point['x'].','.$point['y'], $points)),
            ];
        }

        $topProducts = DB::table('order_items')
            ->join('order_read_models', 'order_items.order_id', '=', 'order_read_models.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('order_read_models.paid_at', [$from, $to])
            ->select('products.id', 'products.name', DB::raw('SUM(order_items.quantity) as units'), DB::raw('SUM(order_items.subtotal) as revenue'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('units')->orderBy('products.id')->limit(5)->get();

        return [
            'revenue' => (float) $metrics->revenue,
            'orderCount' => (int) $metrics->order_count,
            'trend' => $trend,
            'chartMax' => $chartMax,
            'trendCharts' => $trendCharts,
            'periodStart' => $from->locale('id')->translatedFormat('j F Y, H:i'),
            'periodCutoff' => $to->locale('id')->translatedFormat('j F Y, H:i'),
            'periodTimezone' => config('app.timezone'),
            'topProducts' => $topProducts,
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink">
    <x-pos-nav />
    <main class="mx-auto w-full min-w-0 max-w-7xl space-y-5 px-5 py-6 sm:space-y-6 sm:py-8">
        <div class="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
            <div class="min-w-0">
                <h1 class="text-3xl font-bold tracking-tight">Dasbor penjualan</h1>
                <p class="mt-2 text-sm leading-relaxed text-pos-muted" data-test="reporting-period">
                    {{ $periodStart }} – {{ $periodCutoff }}<span class="block text-xs">Zona waktu: {{ $periodTimezone }}</span>
                </p>
                <p class="mt-2 text-sm text-pos-muted">Penjualan dihitung berdasarkan waktu pembayaran.</p>
            </div>
            <div class="shrink-0 space-y-2">
                <div class="flex items-end gap-3">
                    <flux:field class="min-w-0 flex-1 md:w-44">
                        <flux:label for="dashboard-period">Periode</flux:label>
                        <flux:select id="dashboard-period" wire:model.live="range" wire:loading.attr="disabled" wire:target="range,refreshMetrics" class="min-h-11 border-pos-border-strong bg-pos-surface text-pos-ink">
                            <flux:select.option value="today">Hari ini</flux:select.option>
                            <flux:select.option value="week">Minggu ini</flux:select.option>
                            <flux:select.option value="month">Bulan ini</flux:select.option>
                        </flux:select>
                    </flux:field>
                </div>
                <div class="min-h-5 text-sm text-pos-muted" role="status" aria-live="polite">
                    <span wire:loading.delay>Memuat dasbor…</span>
                </div>
            </div>
        </div>
        <div class="space-y-5 sm:space-y-6" aria-busy="false" wire:loading.attr="aria-busy" data-test="dashboard-report">
            <div class="grid gap-4 md:grid-cols-2">
                <div class="flex min-w-0 items-center gap-4 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm sm:p-6" data-test="revenue-card">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-pos-soft text-pos-link" aria-hidden="true"><flux:icon.banknotes class="size-6" /></div>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-pos-muted">Pendapatan tunai</p>
                        <p class="mt-1 break-words text-3xl font-bold tracking-tight tabular-nums">{{ config('pos.currency') }} {{ number_format($revenue, 0) }}</p>
                    </div>
                </div>
                <div class="flex min-w-0 items-center gap-4 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm sm:p-6" data-test="orders-card">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-pos-soft text-pos-link" aria-hidden="true"><flux:icon.shopping-bag class="size-6" /></div>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-pos-muted">Pesanan dibayar</p>
                        <p class="mt-1 break-words text-3xl font-bold tracking-tight tabular-nums">{{ number_format($orderCount) }}</p>
                    </div>
                </div>
            </div>
            <div class="grid items-start gap-5 sm:gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <section class="min-w-0 rounded-2xl border border-pos-border bg-pos-surface p-5 shadow-sm sm:p-6" aria-labelledby="order-trend-heading">
                    <h2 id="order-trend-heading" class="text-lg font-bold">Tren pesanan</h2>
                    <p class="mt-1 text-sm text-pos-muted">Pesanan dibayar {{ in_array($range, ['week', 'month'], true) ? 'per hari' : 'per jam' }}.</p>
                    @if($orderCount === 0)
                        <p class="mt-3 rounded-xl bg-pos-soft px-4 py-3 text-sm text-pos-muted" data-test="trend-empty">Belum ada pesanan dibayar pada periode ini. Grafik menampilkan nilai nol.</p>
                    @endif
                    <div class="mt-4 min-w-0" data-test="order-trend">
                        @foreach($trendCharts as $variant => $chart)
                            <svg wire:key="trend-chart-{{ $variant }}" viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" preserveAspectRatio="none" class="w-full {{ $variant === 'mobile' ? 'block h-60 sm:hidden' : 'hidden h-[280px] sm:block' }}" role="img" aria-labelledby="order-trend-title-{{ $variant }} order-trend-description-{{ $variant }}">
                                <title id="order-trend-title-{{ $variant }}">Tren pesanan dibayar</title>
                                <desc id="order-trend-description-{{ $variant }}">Jumlah pesanan berdasarkan waktu pembayaran. Total {{ number_format($orderCount) }} pesanan pada periode ini. Lihat data tren untuk seluruh nilai.</desc>
                                @foreach($chart['ticks'] as $tick)
                                    <g wire:key="trend-tick-{{ $variant }}-{{ $tick['count'] }}">
                                        <line x1="{{ $chart['left'] }}" x2="{{ $chart['right'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" class="stroke-pos-border" vector-effect="non-scaling-stroke" />
                                        <text x="{{ $chart['left'] - 10 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end" class="fill-pos-muted {{ $variant === 'mobile' ? 'text-[15px]' : 'text-[13px]' }}">{{ $tick['count'] }}</text>
                                    </g>
                                @endforeach
                                @foreach($chart['labels'] as $point)
                                    <text wire:key="trend-label-{{ $variant }}-{{ $point['key'] }}" x="{{ $point['x'] }}" y="{{ $chart['bottom'] + 24 }}" text-anchor="middle" class="fill-pos-muted {{ $variant === 'mobile' ? 'text-[15px]' : 'text-[13px]' }}">{{ $point['label'] }}</text>
                                @endforeach
                                <polyline points="{{ $chart['polyline'] }}" fill="none" class="stroke-pos-link" stroke-width="3" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                                @foreach($chart['points'] as $point)
                                    <circle wire:key="trend-point-{{ $variant }}-{{ $point['key'] }}" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3" class="fill-pos-link">
                                        <title>{{ $point['label'] }}: {{ $point['count'] }} pesanan dibayar</title>
                                    </circle>
                                @endforeach
                            </svg>
                        @endforeach
                    </div>
                    <details wire:key="trend-data" wire:ignore.self class="mt-3 border-t border-pos-border pt-2" data-test="trend-data">
                        <summary class="min-h-11 cursor-pointer rounded-lg py-3 text-sm font-semibold text-pos-link">Lihat data tren</summary>
                        <table class="w-full text-left text-sm" data-test="trend-data-table">
                            <caption class="sr-only">Data tren pesanan dibayar</caption>
                            <thead class="text-pos-muted"><tr><th scope="col" class="py-3 font-medium">Waktu</th><th scope="col" class="py-3 text-right font-medium">Pesanan dibayar</th></tr></thead>
                            <tbody class="divide-y divide-pos-border">
                                @foreach($trend as $point)
                                    <tr wire:key="trend-data-{{ $point['key'] }}"><th scope="row" class="py-3 font-medium">{{ $point['label'] }}</th><td class="py-3 text-right tabular-nums">{{ number_format($point['count']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </details>
                </section>
                <section class="min-w-0 overflow-hidden rounded-2xl border border-pos-border bg-pos-surface shadow-sm" aria-labelledby="top-products-heading">
                    <div class="p-5 sm:p-6">
                        <h2 id="top-products-heading" class="text-lg font-bold">Produk terlaris</h2>
                        <p class="mt-1 text-sm text-pos-muted">Lima produk dengan unit terjual terbanyak dari pesanan dibayar.</p>
                    </div>
                    @if($topProducts->isEmpty())
                        <p class="border-t border-pos-border px-5 py-8 text-sm text-pos-muted sm:px-6" data-test="products-empty">Belum ada penjualan yang dibayar pada periode ini.</p>
                    @else
                        <ol class="divide-y divide-pos-border border-t border-pos-border lg:hidden" data-test="top-products-cards">
                            @foreach($topProducts as $product)
                                <li wire:key="top-product-card-{{ $product->id }}" class="p-5 sm:px-6">
                                    <div class="flex items-start gap-3">
                                        <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-pos-soft text-sm font-bold text-pos-link" aria-label="Peringkat {{ $loop->iteration }}">{{ $loop->iteration }}</span>
                                        <h3 class="min-w-0 break-words font-semibold">{{ $product->name }}</h3>
                                    </div>
                                    <dl class="mt-3 grid min-w-0 grid-cols-2 gap-3 text-sm">
                                        <div><dt class="text-pos-muted">Unit terjual</dt><dd class="mt-1 break-words font-medium tabular-nums">{{ number_format($product->units) }}</dd></div>
                                        <div class="min-w-0 text-right"><dt class="text-pos-muted">Pendapatan</dt><dd class="mt-1 break-words font-semibold tabular-nums">{{ config('pos.currency') }} {{ number_format($product->revenue, 0) }}</dd></div>
                                    </dl>
                                </li>
                            @endforeach
                        </ol>
                        <table class="hidden w-full table-fixed text-left text-sm lg:table" data-test="top-products-table">
                            <thead class="bg-pos-soft text-xs text-pos-muted">
                                <tr><th scope="col" class="w-[43%] px-5 py-3">Nama produk</th><th scope="col" class="w-[20%] px-2 py-3 text-right">Unit terjual</th><th scope="col" class="w-[37%] px-5 py-3 text-right">Pendapatan</th></tr>
                            </thead>
                            <tbody class="divide-y divide-pos-border">
                                @foreach($topProducts as $product)
                                    <tr wire:key="top-product-row-{{ $product->id }}">
                                        <th scope="row" class="px-5 py-4 align-top font-medium"><div class="flex items-start gap-2"><span class="shrink-0 font-bold text-pos-link" aria-label="Peringkat {{ $loop->iteration }}">{{ $loop->iteration }}.</span><span class="min-w-0 break-words">{{ $product->name }}</span></div></th>
                                        <td class="break-words px-2 py-4 text-right align-top tabular-nums">{{ number_format($product->units) }}</td>
                                        <td class="break-words px-5 py-4 text-right align-top font-semibold tabular-nums">{{ config('pos.currency') }} {{ number_format($product->revenue, 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            </div>
        </div>
    </main>
</div>
