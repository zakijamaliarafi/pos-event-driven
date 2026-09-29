<header class="border-b border-pos-border bg-pos-surface">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-5 px-5 py-4">
        <a href="{{ route('home') }}" class="flex items-center gap-3 text-lg font-bold text-pos-ink">
            <img src="{{ asset('oemah-tahu-mark.svg') }}" class="h-10 w-10 shrink-0" alt="">
            <span class="leading-tight">Oemah Tahu<br class="sm:hidden"> Purwokerto</span>
        </a>
        <nav class="flex flex-1 flex-wrap gap-4 text-sm font-medium text-pos-muted" aria-label="Navigasi utama">
            @auth
                @if(auth()->user()->hasRole('cashier')) <a class="hover:text-pos-link" href="{{ route('order.create') }}">Kasir</a> @endif
                @if(auth()->user()->hasRole('manager'))
                    <a class="hover:text-pos-link" href="{{ route('menu.menu') }}">Katalog</a>
                    <a class="hover:text-pos-link" href="{{ route('menu.inventory') }}">Stok</a>
                    <a class="hover:text-pos-link" href="{{ route('menu.recipes') }}">Resep</a>
                    <a class="hover:text-pos-link" href="{{ route('dashboard.revenue') }}">Dasbor</a>
                @endif
                @if(auth()->user()->hasRole('kitchen')) <a class="hover:text-pos-link" href="{{ route('kitchen.view') }}">Dapur</a> @endif
                @if(auth()->user()->hasRole('waiter')) <a class="hover:text-pos-link" href="{{ route('waiter.view') }}">Pramusaji</a> @endif
                @if(auth()->user()->hasRole('owner')) <a class="hover:text-pos-link" href="{{ route('dashboard.revenue') }}">Dasbor</a> @endif
            @endauth
            <a class="hover:text-pos-link" href="{{ route('customer.order') }}">Pesan sebagai pelanggan</a>
        </nav>
        <select x-data x-model="$flux.appearance" aria-label="Tampilan" class="rounded-lg border border-pos-border-strong bg-pos-surface px-3 py-2 text-sm text-pos-ink">
            <option value="light">Terang</option>
            <option value="dark">Gelap</option>
            <option value="system">Sistem</option>
        </select>
        @auth
            <details class="relative text-sm text-pos-ink">
                <summary class="cursor-pointer rounded-lg border border-pos-border-strong px-3 py-2">Notifikasi ({{ auth()->user()->unreadNotifications()->count() }})</summary>
                <div class="absolute right-0 z-20 mt-2 w-72 space-y-2 rounded-xl border border-pos-border bg-pos-surface p-4 shadow-lg">
                    @forelse(auth()->user()->unreadNotifications()->latest()->limit(5)->get() as $alert)
                        <p class="border-b border-pos-border pb-2 text-xs"><strong class="block text-pos-link">{{ $alert->data['title'] }}</strong>{{ $alert->data['message'] }}</p>
                    @empty
                        <p class="text-xs text-pos-muted">Belum ada notifikasi baru.</p>
                    @endforelse
                </div>
            </details>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-lg border border-pos-border-strong px-3 py-2 text-sm font-medium text-pos-ink hover:bg-pos-soft">Keluar</button>
            </form>
        @endauth
    </div>
</header>
