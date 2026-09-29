<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <title>{{ filled($title ?? null) ? $title.' - '.config('app.name') : config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance

        @if (auth()->check() && request()->routeIs('order.create', 'kitchen.view', 'waiter.view', 'dashboard.revenue'))
            @vite('resources/js/realtime.js')
        @endif

        @if (request()->routeIs('customer.order'))
            @vite('resources/js/customer-realtime.js')
        @endif

        @livewireStyles
    </head>
    <body>
        @if(request()->routeIs('profile.edit', 'security.edit', 'appearance.edit'))
            <div class="min-h-screen bg-pos-canvas text-pos-ink">
                <x-pos-nav />
                <main class="mx-auto max-w-5xl rounded-2xl px-5 py-8">{{ $slot }}</main>
            </div>
        @else
            {{ $slot }}
        @endif

        @livewireScripts
        @fluxScripts
    </body>
</html>
