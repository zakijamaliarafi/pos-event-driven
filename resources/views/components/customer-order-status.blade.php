@props(['order', 'highlighted' => false])

@php
    $steps = ['Pemeriksaan stok', 'Verifikasi pramusaji', 'Antrean dapur', 'Disiapkan', 'Siap disajikan', 'Pembayaran', 'Selesai'];
    $currentStep = match ($order->status) {
        'awaiting_inventory' => 0,
        'awaiting_verification', 'verifying' => 1,
        'pending' => 2,
        'preparing' => 3,
        'ready' => 4,
        'awaiting_payment' => 5,
        'completed' => 6,
        default => null,
    };
    $description = match ($order->status) {
        'awaiting_inventory' => 'Pesanan diterima. Ketersediaan bahan sedang diperiksa.',
        'awaiting_verification' => 'Pramusaji akan memeriksa dan memverifikasi pesanan Anda.',
        'verifying' => 'Sudah diverifikasi. Pemakaian stok sedang diproses sebelum pesanan masuk dapur.',
        'pending' => 'Pesanan telah masuk antrean dapur.',
        'preparing' => 'Menu Anda sedang disiapkan.',
        'ready' => 'Menu siap dan akan disajikan oleh pramusaji.',
        'awaiting_payment' => 'Pesanan sudah disajikan. Bayar tunai di kasir setelah makan.',
        'waiting_payment' => 'Pesanan lama ini menunggu pembayaran tunai di muka di kasir.',
        'completed' => 'Pembayaran telah dicatat. Terima kasih atas pesanan Anda.',
        'rejected' => 'Pesanan ditolak. Hubungi pramusaji untuk informasi lebih lanjut.',
        'cancelled' => 'Pesanan dibatalkan. Bahan yang dicadangkan akan dikembalikan.',
        'expired' => 'Pesanan kedaluwarsa. Silakan buat pesanan baru.',
        default => 'Hubungi pramusaji untuk informasi pesanan.',
    };
    $unsuccessful = in_array($order->status, ['rejected', 'cancelled', 'expired'], true);
@endphp

<article {{ $attributes->class('rounded-2xl border bg-pos-surface p-4 sm:p-5 '.($highlighted ? 'border-pos-action ring-2 ring-pos-action/20' : 'border-pos-border')) }} data-test="tracked-order-{{ $order->id }}">
    @if($highlighted)<p class="mb-2 text-xs font-semibold text-pos-link">Pesanan terbaru Anda</p>@endif
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-pos-muted">Nomor pesanan</p>
            <h4 class="mt-1 break-all font-bold">{{ $order->order_number }}</h4>
        </div>
        <span class="max-w-full rounded-full border px-3 py-1 text-xs font-semibold {{ $unsuccessful ? 'border-pos-danger-border bg-pos-danger-soft text-pos-danger' : 'border-pos-border-strong bg-pos-soft text-pos-link' }}">{{ __('orders.status.'.$order->status) }}</span>
    </div>
    <div class="mt-3 flex flex-wrap justify-between gap-2 text-xs text-pos-muted">
        <span>{{ $order->table_number ? 'Meja '.$order->table_number : __('orders.type.'.$order->order_type) }} · {{ $order->created_at->timezone(config('app.timezone'))->translatedFormat('d M Y, H:i') }}</span>
        <span class="break-words font-semibold text-pos-ink">{{ config('pos.currency') }} {{ number_format($order->total_amount, 0) }}</span>
    </div>
    <p class="mt-4 text-sm leading-relaxed {{ $unsuccessful ? 'text-pos-danger' : 'text-pos-muted' }}">{{ $description }}</p>
    @if($currentStep !== null)
        <ol class="mt-5 space-y-3" aria-label="Progres pesanan {{ $order->order_number }}">
            @foreach($steps as $index => $step)
                @php($done = $index < $currentStep || $order->status === 'completed')
                <li class="flex items-center gap-3 text-sm {{ $index <= $currentStep ? 'text-pos-ink' : 'text-pos-muted' }}" @if($index === $currentStep && ! $done) aria-current="step" @endif>
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full {{ $done ? 'bg-pos-action text-pos-action-foreground' : ($index === $currentStep ? 'border-2 border-pos-action text-pos-link' : 'bg-pos-soft text-pos-muted') }}" aria-hidden="true">
                        @if($done)<flux:icon.check class="size-4" />@else<span class="text-xs">{{ $index + 1 }}</span>@endif
                    </span>
                    <span class="{{ $index === $currentStep ? 'font-semibold' : '' }}">{{ $step }}<span class="sr-only">{{ $done ? ', selesai' : ($index === $currentStep ? ', tahap saat ini' : ', belum dimulai') }}</span></span>
                </li>
            @endforeach
        </ol>
    @endif
    @if(in_array($order->status, ['awaiting_inventory', 'awaiting_verification'], true))
        <flux:button wire:click="cancel({{ $order->id }})" wire:confirm="Batalkan pesanan ini sebelum diverifikasi pramusaji?" wire:loading.attr="disabled" wire:target="cancel({{ $order->id }})" class="mt-4 min-h-11 text-pos-danger!">
            <span wire:loading.remove wire:target="cancel({{ $order->id }})">Batalkan pesanan</span>
            <span wire:loading wire:target="cancel({{ $order->id }})">Membatalkan…</span>
        </flux:button>
    @endif
</article>
