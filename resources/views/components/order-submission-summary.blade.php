@props(['order', 'actions' => false])

@if($order)
    <div role="status" class="mb-4 min-w-0 space-y-3 rounded-xl border border-pos-border bg-pos-soft p-4 text-sm" data-submission-recovery>
        <p>{{ __('orders.messages.submission_found') }}</p>
        <p class="break-words font-semibold">{{ $order->order_number }} · {{ __('orders.status.'.$order->status) }}</p>
        @if($actions)
            <div class="flex flex-wrap gap-2">
                <flux:button type="button" wire:click="viewPreviousSubmission" wire:loading.attr="disabled" wire:target="submit,checkSubmission,viewPreviousSubmission,startNewSubmission" class="min-h-11">Lihat pesanan sebelumnya</flux:button>
                <flux:button type="button" wire:click="startNewSubmission" wire:loading.attr="disabled" wire:target="submit,checkSubmission,viewPreviousSubmission,startNewSubmission" class="min-h-11">Mulai pesanan baru</flux:button>
            </div>
        @endif
    </div>
@endif
