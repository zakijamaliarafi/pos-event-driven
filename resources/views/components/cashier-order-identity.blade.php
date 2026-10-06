@props(['order', 'highlighted' => false])
<div class="min-w-0">
    @if($highlighted)<p class="mb-1 text-xs font-semibold text-pos-link">Penjualan baru dikirim</p>@endif
    <p class="break-all font-semibold">{{ $order->order_number }}</p>
    <p class="mt-1 break-words">{{ $order->customer_name }}</p>
    <p class="mt-1 text-xs text-pos-muted">{{ $order->order_type === 'takeaway' ? __('orders.type.takeaway') : 'Meja '.$order->table_number }} · {{ $order->device_id === null ? 'Penjualan kasir' : 'Pesanan pelanggan' }}</p>
    <time datetime="{{ $order->created_at->toIso8601String() }}" class="mt-1 block text-xs text-pos-muted">{{ $order->created_at->timezone(config('app.timezone'))->translatedFormat('d M Y H:i') }}</time>
</div>
