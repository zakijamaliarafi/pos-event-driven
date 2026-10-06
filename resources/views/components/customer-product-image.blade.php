@props(['path' => null, 'showLabel' => true])

<div {{ $attributes->class('relative shrink-0 overflow-hidden bg-pos-soft') }} x-data="{ broken: false }" x-init="const image = $el.querySelector('img'); if (image?.complete && image.naturalWidth === 0) broken = true;">
    <div class="absolute inset-0 flex flex-col items-center justify-center gap-1 text-pos-muted" @if($path) x-show="broken" x-cloak @endif data-customer-image-fallback>
        <flux:icon.photo class="size-8" />
        <span class="{{ $showLabel ? 'px-2 text-center text-xs' : 'sr-only' }}">Foto belum tersedia</span>
    </div>
    @if($path)
        <img src="{{ Storage::disk('public')->url($path) }}" alt="" loading="lazy" class="absolute inset-0 h-full w-full object-cover" x-show="!broken" x-on:error="broken = true" x-on:load="broken = false" />
    @endif
</div>
