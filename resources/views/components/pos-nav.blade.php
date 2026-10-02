@php($pageUrl = \Livewire\Livewire::originalUrl())
<header class="border-b border-pos-border bg-pos-surface">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-5 px-5 py-4">
        <a href="{{ route('home') }}" aria-label="POS" class="flex items-center gap-3 text-lg font-bold text-pos-ink">
            <img src="{{ asset('oemah-tahu-mark.svg') }}" class="h-10 w-10 shrink-0" alt="">
            @if($pageUrl === route('customer.order'))
                <span class="leading-tight">Oemah Tahu<br class="sm:hidden"> Purwokerto</span>
            @endif
        </a>
        <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-4 text-sm font-medium text-pos-muted" aria-label="Navigasi utama">
            @auth
                @if(auth()->user()->hasRole('cashier')) <a class="hover:text-pos-link" href="{{ route('order.create') }}">Kasir</a> @endif
                @if(auth()->user()->hasRole('manager'))
                    @foreach(['dashboard.revenue' => 'Dasbor', 'menu.menu' => 'Produk', 'menu.category' => 'Kategori', 'menu.inventory' => 'Stok', 'menu.ingredient' => 'Bahan', 'menu.recipes' => 'Resep', 'menu.discount' => 'Diskon'] as $route => $label)
                        <a href="{{ route($route) }}" @if($pageUrl === route($route)) aria-current="page" @endif class="rounded-lg px-3 py-2 {{ $pageUrl === route($route) ? 'bg-pos-action font-semibold text-pos-action-foreground' : 'hover:bg-pos-soft hover:text-pos-link' }}">{{ $label }}</a>
                    @endforeach
                @endif
                @if(auth()->user()->hasRole('kitchen')) <a class="hover:text-pos-link" href="{{ route('kitchen.view') }}">Dapur</a> @endif
                @if(auth()->user()->hasRole('waiter')) <a class="hover:text-pos-link" href="{{ route('waiter.view') }}">Pramusaji</a> @endif
            @endauth
        </nav>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" square aria-label="Tampilan" x-bind:aria-label="$flux.dark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap'" class="border-pos-border-strong bg-pos-surface text-pos-ink hover:bg-pos-soft" data-test="theme-toggle">
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
            @endauth
        </div>
    </div>
</header>
