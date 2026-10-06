@props(['order'])
<div class="space-y-2">
    <p class="break-words font-medium text-pos-link">{{ $order->status === 'verifying' ? 'Verifikasi sedang diproses' : __('orders.status.'.$order->status) }}</p>
    @if($order->has_cash_payment && in_array($order->status, ['waiting_payment', 'awaiting_payment'], true))<p class="rounded-lg bg-pos-soft px-2 py-2 text-xs font-semibold">Pembayaran sedang diproses</p>
    @elseif($order->status === 'waiting_payment')<p class="text-xs text-pos-muted">Pembayaran di muka</p>
    @elseif($order->status === 'awaiting_payment')<p class="text-xs text-pos-muted">Pembayaran setelah makan</p>@endif
    @if($order->device_id === null && $order->expires_at && in_array($order->status, ['awaiting_inventory', 'waiting_payment'], true) && ! $order->has_cash_payment)
        <p class="text-xs text-pos-muted">Batas pembayaran <time datetime="{{ $order->expires_at->toIso8601String() }}">{{ $order->expires_at->timezone(config('app.timezone'))->translatedFormat('d M Y H:i') }}</time></p>
        @if($order->expires_at->isPast())<p class="text-xs text-pos-danger">Batas pembayaran telah lewat. Menunggu pemrosesan kedaluwarsa.</p>@endif
    @endif
</div>
