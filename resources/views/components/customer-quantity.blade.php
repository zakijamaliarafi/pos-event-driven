@props(['productId', 'name', 'quantity', 'limit', 'context' => 'menu'])

<div class="flex items-center justify-between gap-1" role="group" aria-label="Jumlah {{ $name }}">
    <flux:button square wire:click="decrement({{ $productId }})" wire:loading.attr="disabled" wire:target="submit,decrement({{ $productId }}),increment({{ $productId }})" class="min-h-11 min-w-11" aria-label="Kurangi {{ $name }}" icon="minus" />
    <span class="min-w-5 text-center text-sm font-semibold tabular-nums" aria-live="polite">{{ $quantity }}</span>
    <flux:button square wire:click="increment({{ $productId }})" wire:loading.attr="disabled" wire:target="submit,decrement({{ $productId }}),increment({{ $productId }})" :disabled="$quantity >= $limit" wire:key="quantity-add-{{ $context }}-{{ $productId }}-{{ $quantity >= $limit ? 'blocked' : 'ready' }}" class="min-h-11 min-w-11" aria-label="Tambah {{ $name }}" icon="plus" />
</div>
