@props(['productId', 'name', 'quantity', 'limit', 'context', 'invalid' => false])
@php
    $parsed = is_int($quantity) || is_string($quantity) ? filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
    $invalid = $invalid || $parsed === false || $parsed > $limit;
@endphp
<div class="flex min-w-0 items-center gap-2" role="group" aria-label="Jumlah {{ $name }}">
    <flux:button type="button" square icon="minus" wire:click="decrement({{ $productId }})" wire:loading.attr="disabled" wire:target="checkSubmission,viewPreviousSubmission,startNewSubmission,submit,increment,decrement,remove" :disabled="$parsed === false || $parsed < 1" wire:key="cashier-minus-{{ $context }}-{{ $productId }}-{{ $parsed === false || $parsed < 1 ? 'blocked' : 'ready' }}" class="min-h-11 min-w-11" aria-label="Kurangi {{ $name }}" />
    <label for="cashier-{{ $context }}-quantity-{{ $productId }}" class="sr-only">Jumlah {{ $name }}</label>
    <input id="cashier-{{ $context }}-quantity-{{ $productId }}" type="number" min="0" max="{{ $limit }}" step="1" inputmode="numeric" value="{{ is_int($quantity) || is_string($quantity) ? $quantity : '' }}" wire:model.fill.live.debounce.300ms="quantities.{{ $productId }}" wire:loading.attr="disabled" wire:target="checkSubmission,viewPreviousSubmission,startNewSubmission,submit,increment,decrement,remove" aria-invalid="{{ $invalid ? 'true' : 'false' }}" @if($context !== 'menu') aria-describedby="cashier-{{ $context }}-warning-{{ $productId }}" @endif class="min-h-11 min-w-0 w-full rounded-lg border border-pos-border-strong px-2 py-2 text-center text-sm font-semibold tabular-nums" />
    <flux:button type="button" square icon="plus" wire:click="increment({{ $productId }})" wire:loading.attr="disabled" wire:target="checkSubmission,viewPreviousSubmission,startNewSubmission,submit,increment,decrement,remove" :disabled="$parsed === false || $parsed >= $limit" wire:key="cashier-plus-{{ $context }}-{{ $productId }}-{{ $parsed === false || $parsed >= $limit ? 'blocked' : 'ready' }}" class="min-h-11 min-w-11" aria-label="Tambah {{ $name }}" />
</div>
