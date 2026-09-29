<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-pos-canvas text-pos-ink antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="mb-1 flex h-24 w-24 items-center justify-center rounded-md">
                        <img src="{{ asset('oemah-tahu-mark.svg') }}" alt="" class="h-20 w-20">
                    </span>
                    <span class="text-center text-lg font-bold leading-tight text-pos-ink">Oemah Tahu<br>Purwokerto</span>
                </a>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
