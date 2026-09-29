@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="Oemah Tahu Purwokerto" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md">
            <x-app-logo-icon class="size-8" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="Oemah Tahu Purwokerto" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md">
            <x-app-logo-icon class="size-8" />
        </x-slot>
    </flux:brand>
@endif
