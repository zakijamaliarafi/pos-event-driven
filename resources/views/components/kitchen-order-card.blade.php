@props(['order', 'lane', 'error' => null])
@php
    $createdAt = $order->created_at->timezone(config('app.timezone'));
    $minutes = max(0, (int) $order->created_at->diffInMinutes(now()));
    $action = $lane === 'pending' ? 'start' : 'ready';
@endphp
<article wire:key="kitchen-order-{{ $lane }}-{{ $order->id }}" data-kitchen-order="{{ $order->id }}" tabindex="-1" aria-labelledby="kitchen-order-title-{{ $order->id }}" class="kitchen-order-card relative min-w-0 rounded-xl border border-pos-border bg-pos-surface p-4 shadow-sm sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <h3 id="kitchen-order-title-{{ $order->id }}" class="text-2xl font-bold">{{ $order->order_type === 'takeaway' ? __('orders.type.takeaway') : 'Meja '.$order->table_number }}</h3>
        <time datetime="{{ $createdAt->toIso8601String() }}" title="Dipesan {{ $createdAt->translatedFormat('d F Y H:i:s') }}" class="text-xs font-semibold tabular-nums text-pos-muted">
            <span data-kitchen-age="{{ $createdAt->getTimestamp() }}">{{ $minutes < 1 ? 'Baru masuk' : $minutes.' menit lalu' }}</span>
            <span class="sr-only"> · Dipesan {{ $createdAt->translatedFormat('d F Y H:i:s') }}</span>
        </time>
    </div>
    <p class="mt-2 break-words font-medium">{{ $order->customer_name }}</p>
    <p class="mt-1 break-all text-xs text-pos-muted">{{ $order->order_number }}</p>
    <ul class="my-5 space-y-3">
        @foreach($order->items as $item)
            <li wire:key="kitchen-item-{{ $lane }}-{{ $item->id }}" class="flex items-start gap-3">
                <strong class="kitchen-quantity flex min-h-9 min-w-9 shrink-0 items-center justify-center rounded-lg px-2 tabular-nums"><span class="sr-only">Jumlah </span>{{ $item->quantity }}</strong>
                <div class="min-w-0 pt-1">
                    <p class="break-words text-base font-medium">{{ $item->product?->name ?? 'Produk tidak tersedia' }}</p>
                    @if(filled($item->notes))<p class="mt-1 break-words text-sm text-pos-muted"><span class="font-semibold">Catatan:</span> {{ $item->notes }}</p>@endif
                </div>
            </li>
        @endforeach
    </ul>
    @if($error)<p role="alert" class="mb-3 rounded-lg border border-pos-danger-border bg-pos-danger-soft p-3 text-sm text-pos-danger">{{ $error }}</p>@endif
    @if(in_array($lane, ['pending', 'preparing'], true))
        <flux:button wire:key="kitchen-action-{{ $lane }}-{{ $order->id }}" wire:click="{{ $action }}({{ $order->id }})" wire:loading.attr="disabled" wire:target="{{ $action }}({{ $order->id }})" data-kitchen-action data-kitchen-destination="{{ $lane === 'pending' ? 'preparing' : 'ready' }}" variant="primary" class="kitchen-action min-h-12 w-full whitespace-normal" aria-label="{{ $lane === 'pending' ? 'Mulai siapkan' : 'Tandai siap' }} {{ $order->order_number }}">
            <span wire:loading.remove wire:target="{{ $action }}({{ $order->id }})">{{ $lane === 'pending' ? 'Mulai siapkan' : 'Tandai siap' }}</span>
            <span wire:loading wire:target="{{ $action }}({{ $order->id }})">Menyimpan…</span>
        </flux:button>
    @elseif($lane === 'ready')
        <p class="kitchen-readonly rounded-lg px-3 py-3 text-center text-sm font-semibold">Menunggu pramusaji</p>
    @else
        <div class="kitchen-readonly space-y-1 rounded-lg px-3 py-3 text-sm">
            <p class="font-semibold">Sudah disajikan</p>
            <p>{{ $order->status === 'awaiting_payment' ? 'Belum dibayar · menunggu kasir' : 'Pembayaran selesai' }}</p>
            @if($order->served_at)
                <p class="text-xs">Disajikan {{ \Illuminate\Support\Carbon::parse($order->served_at)->timezone(config('app.timezone'))->translatedFormat('d M Y H:i') }}</p>
            @else
                <p class="text-xs">Waktu penyajian tidak tersedia</p>
            @endif
        </div>
    @endif
</article>
