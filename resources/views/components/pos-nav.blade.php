@props(['customerToolbar' => false])
@php
    $pageUrl = \Livewire\Livewire::originalUrl();
    $isCustomerPage = parse_url($pageUrl, PHP_URL_PATH) === parse_url(route('customer.order'), PHP_URL_PATH);
@endphp
<header x-data="{ navigationOpen: false }" x-on:keydown.escape="if (navigationOpen) { navigationOpen = false; $refs.navigationToggle.focus() }" class="border-b border-pos-border bg-pos-surface {{ $customerToolbar ? 'sticky top-0 z-30' : '' }}">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center {{ $customerToolbar ? 'gap-3 px-4 py-3 sm:px-6' : 'gap-5 px-5 py-4' }}">
        <a href="{{ route('home') }}" aria-label="POS" class="flex items-center gap-3 text-lg font-bold text-pos-ink">
            <img src="{{ asset('oemah-tahu-mark.svg') }}" class="h-10 w-10 shrink-0" alt="">
            @if($isCustomerPage)
                <span class="leading-tight">Oemah Tahu<br class="sm:hidden"> Purwokerto</span>
            @endif
        </a>
        @auth
        <nav id="pos-navigation" x-cloak x-bind:class="navigationOpen ? 'flex' : 'hidden'" class="order-3 w-full min-w-0 flex-col items-stretch gap-2 border-t border-pos-border pt-3 text-sm font-medium text-pos-muted *:inline-flex *:min-h-11 *:shrink-0 *:items-center lg:order-none lg:flex lg:w-auto lg:flex-1 lg:flex-row lg:items-center lg:gap-4 lg:border-0 lg:pt-0" aria-label="Navigasi utama">
                @if(auth()->user()->hasRole('cashier')) <a class="hover:text-pos-link" href="{{ route('order.create') }}">Kasir</a> @endif
                @if(auth()->user()->hasRole('manager'))
                    @foreach(['dashboard.revenue' => 'Dasbor', 'menu.ingredient' => 'Bahan', 'menu.recipes' => 'Resep', 'menu.category' => 'Kategori', 'menu.menu' => 'Produk', 'menu.discount' => 'Diskon'] as $route => $label)
                        <a href="{{ route($route) }}" @if($pageUrl === route($route)) aria-current="page" @endif class="rounded-lg px-3 py-2 {{ $pageUrl === route($route) ? 'bg-pos-action font-semibold text-pos-action-foreground' : 'hover:bg-pos-soft hover:text-pos-link' }}">{{ $label }}</a>
                    @endforeach
                @endif
                @if(auth()->user()->hasRole('kitchen')) <a class="hover:text-pos-link" href="{{ route('kitchen.view') }}">Dapur</a> @endif
                @if(auth()->user()->hasRole('waiter')) <a class="hover:text-pos-link" href="{{ route('waiter.view') }}">Pramusaji</a> @endif
        </nav>
        @endauth
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" square aria-label="Tampilan" x-bind:aria-label="$flux.dark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap'" class="border-pos-border-strong bg-pos-surface text-pos-ink hover:bg-pos-soft {{ $customerToolbar ? 'min-h-11 min-w-11' : '' }}" data-test="theme-toggle">
                <flux:icon.moon class="size-5 dark:hidden" />
                <flux:icon.sun class="hidden size-5 dark:block" />
            </flux:button>
            @auth
                @php($unreadCount = auth()->user()->unreadNotifications()->count())
                <flux:dropdown position="bottom" align="end">
                    <flux:button square aria-label="Notifikasi ({{ $unreadCount }})" class="relative border-pos-border-strong bg-pos-surface text-pos-ink hover:bg-pos-soft" data-test="notifications-toggle">
                        <flux:icon.bell variant="mini" class="size-5" />
                        @if($unreadCount > 0)
                            <span class="absolute -right-1 -top-1 flex min-w-5 items-center justify-center rounded-full bg-blue-600 px-1 text-xs font-semibold text-white" aria-hidden="true" data-test="notifications-count">{{ $unreadCount }}</span>
                        @endif
                    </flux:button>
                    <div popover class="w-72 max-w-[calc(100vw-2rem)] space-y-2 rounded-xl border border-pos-border bg-pos-surface p-4 text-pos-ink shadow-lg" data-test="notifications-panel">
                        @forelse(auth()->user()->unreadNotifications()->latest()->limit(5)->get() as $alert)
                            <p class="border-b border-pos-border pb-2 text-xs"><strong class="block text-pos-link">{{ $alert->data['title'] }}</strong>{{ $alert->data['message'] }}</p>
                        @empty
                            <p class="text-xs text-pos-muted">Belum ada notifikasi baru.</p>
                        @endforelse
                    </div>
                </flux:dropdown>
                <flux:dropdown position="bottom" align="end">
                    <flux:button icon="user" square aria-label="Menu pengguna" class="border-pos-border-strong bg-pos-surface text-pos-ink hover:bg-pos-soft" data-test="user-menu-toggle" />
                    <flux:menu class="max-w-[calc(100vw-2rem)] border-pos-border bg-pos-surface text-pos-ink">
                        <flux:menu.heading class="break-words text-pos-ink">{{ auth()->user()->name }}</flux:menu.heading>
                        <flux:menu.separator />
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">Keluar</flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
                <button type="button" x-ref="navigationToggle" x-on:click="navigationOpen = ! navigationOpen" x-bind:aria-expanded="navigationOpen" aria-expanded="false" aria-controls="pos-navigation" aria-label="Buka atau tutup navigasi" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-pos-border-strong bg-pos-surface text-pos-ink shadow-sm hover:bg-pos-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-pos-link lg:hidden">
                    <flux:icon.bars-3 x-show="! navigationOpen" class="size-5" />
                    <flux:icon.x-mark x-cloak x-show="navigationOpen" class="size-5" />
                </button>
            @endauth
        </div>
        @if($customerToolbar)
            <div class="order-4 flex w-full min-w-0 flex-wrap items-center justify-between gap-3">
                {{ $customerContext ?? '' }}
                <div class="flex min-w-0 flex-wrap items-center gap-2">{{ $customerActions ?? '' }}</div>
            </div>
        @endif
    </div>
</header>
