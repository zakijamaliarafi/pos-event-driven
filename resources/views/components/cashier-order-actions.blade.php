@props(['order', 'context'])
<div class="flex flex-wrap gap-2">
    @if($order->can_pay)<flux:button type="button" variant="primary" wire:click="openPayment({{ $order->id }})" wire:loading.attr="disabled" wire:target="openPayment,openCancellation,pay,cancel" data-cashier-open="pay-{{ $order->id }}" wire:key="cashier-pay-{{ $context }}-{{ $order->id }}" class="min-h-11 whitespace-normal" aria-label="Catat tunai {{ $order->order_number }}">Catat tunai</flux:button>@endif
    @if($order->can_cancel)<flux:button type="button" wire:click="openCancellation({{ $order->id }})" wire:loading.attr="disabled" wire:target="openPayment,openCancellation,pay,cancel" data-cashier-open="cancel-{{ $order->id }}" wire:key="cashier-cancel-{{ $context }}-{{ $order->id }}" class="min-h-11 whitespace-normal text-pos-danger!" aria-label="Batalkan {{ $order->order_number }}">Batalkan</flux:button>@endif
    @if(! $order->can_pay && ! $order->can_cancel)<p class="text-xs text-pos-muted">Tidak ada tindakan kasir saat ini.</p>@endif
</div>
