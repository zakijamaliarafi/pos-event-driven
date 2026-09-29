@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-pos-link']) }}>
        {{ $status }}
    </div>
@endif
