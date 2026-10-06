@props(['order', 'lane', 'showActions' => true])
@php
    $prefix = $showActions ? 'waiter-order' : 'waiter-confirm-order';
    $orderedAt = $order->created_at->timezone(config('app.timezone'));
    $readyAt = $order->updated_at->timezone(config('app.timezone'));
@endphp
<article wire:key="{{ $prefix }}-{{ $lane }}-{{ $order->id }}" aria-labelledby="{{ $prefix }}-title-{{ $order->id }}" class="min-w-0 rounded-2xl border border-pos-border bg-pos-surface p-4 shadow-sm sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <h3 id="{{ $prefix }}-title-{{ $order->id }}" class="text-2xl font-bold">{{ $order->order_type === 'takeaway' ? __('orders.type.takeaway') : 'Meja '.$order->table_number }}</h3>
        <span class="max-w-full rounded-lg bg-pos-soft px-2.5 py-1 text-xs font-semibold text-pos-link">{{ $order->status === 'verifying' ? 'Verifikasi sedang diproses' : __('orders.status.'.$order->status) }}</span>
    </div>
    <p class="mt-3 break-words font-semibold">{{ $order->customer_name ?: 'Pelanggan' }}</p>
    <p class="mt-1 break-all text-xs text-pos-muted">{{ $order->order_number }}</p>
    <div class="mt-2 space-y-1 text-xs text-pos-muted">
        <p>Dipesan <time datetime="{{ $orderedAt->toIso8601String() }}" class="tabular-nums">{{ $orderedAt->translatedFormat('d M Y H:i') }}</time></p>
        @if($order->status === 'ready')<p>Siap sejak <time datetime="{{ $readyAt->toIso8601String() }}" class="tabular-nums">{{ $readyAt->translatedFormat('d M Y H:i') }}</time></p>@endif
    </div>
    <ul class="my-4 space-y-3 border-y border-pos-border py-4" aria-label="Item pesanan">
        @foreach($order->items as $item)
            <li wire:key="{{ $prefix }}-item-{{ $item->id }}" class="flex items-start gap-3">
                <strong class="flex min-h-9 min-w-9 shrink-0 items-center justify-center rounded-lg bg-pos-soft px-2 tabular-nums"><span class="sr-only">Jumlah </span>{{ $item->quantity }}</strong>
                <div class="min-w-0 pt-1">
                    <p class="break-words font-medium">{{ $item->product?->name ?? 'Produk tidak tersedia' }}</p>
                    @if(filled($item->notes))<p class="mt-1 break-words text-sm text-pos-muted"><span class="font-semibold">Catatan:</span> {{ $item->notes }}</p>@endif
                </div>
            </li>
        @endforeach
    </ul>
    <div class="flex flex-wrap justify-between gap-2 font-semibold"><span>Total pesanan</span><span class="break-all">{{ config('pos.currency') }} {{ number_format((float) $order->total_amount, str_ends_with($order->total_amount, '.00') ? 0 : 2) }}</span></div>
    @if($showActions)
    <p class="mt-2 text-sm leading-relaxed text-pos-muted">{{ $lane === 'awaiting_verification' ? 'Verifikasi memulai pemrosesan sebelum pesanan masuk dapur.' : ($order->is_paid ? 'Sudah dibayar · selesai setelah disajikan.' : 'Belum dibayar · kasir mencatat tunai setelah disajikan.') }}</p>
        <div class="mt-4 space-y-3">
            @php($action = $lane === 'ready' ? 'serve' : 'verify')
            <flux:button type="button" wire:click="openConfirmation({{ $order->id }}, '{{ $action }}')" wire:loading.attr="disabled" wire:target="openConfirmation,verify,reject,serve" data-waiter-open="{{ $action }}-{{ $order->id }}" data-waiter-lane="{{ $lane }}" variant="primary" class="min-h-12 w-full whitespace-normal" aria-label="{{ $lane === 'ready' ? 'Tandai sudah disajikan' : 'Verifikasi' }} {{ $order->order_number }}">
                <span wire:loading.remove wire:target="openConfirmation({{ $order->id }}, '{{ $action }}')">{{ $lane === 'ready' ? 'Tandai sudah disajikan' : 'Verifikasi pesanan' }}</span>
                <span wire:loading wire:target="openConfirmation({{ $order->id }}, '{{ $action }}')">Membuka konfirmasi…</span>
            </flux:button>
            @if($lane === 'awaiting_verification')
                <div class="border-t border-pos-border pt-3">
                    <flux:button type="button" wire:click="openConfirmation({{ $order->id }}, 'reject')" wire:loading.attr="disabled" wire:target="openConfirmation,verify,reject,serve" data-waiter-open="reject-{{ $order->id }}" data-waiter-lane="{{ $lane }}" class="min-h-11 w-full whitespace-normal border-pos-danger-border! text-pos-danger!" aria-label="Tolak {{ $order->order_number }}">Tolak pesanan</flux:button>
                </div>
            @endif
        </div>
    @endif
</article>
