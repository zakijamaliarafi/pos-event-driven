<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-5 px-5 py-4">
        <a href="{{ route('home') }}" class="flex items-center gap-3 text-lg font-bold text-slate-900">
            <img src="{{ asset('pos-logo.svg') }}" class="h-9 w-9" alt="POS">
            POS
        </a>
        <nav class="flex flex-1 flex-wrap gap-4 text-sm font-medium text-slate-600">
            @auth
                @if(auth()->user()->hasRole('cashier')) <a class="hover:text-blue-700" href="{{ route('order.create') }}">Cashier</a> @endif
                @if(auth()->user()->hasRole('manager'))
                    <a class="hover:text-blue-700" href="{{ route('menu.menu') }}">Catalog</a>
                    <a class="hover:text-blue-700" href="{{ route('menu.inventory') }}">Inventory</a>
                    <a class="hover:text-blue-700" href="{{ route('menu.recipes') }}">Recipes</a>
                    <a class="hover:text-blue-700" href="{{ route('dashboard.revenue') }}">Dashboard</a>
                @endif
                @if(auth()->user()->hasRole('kitchen')) <a class="hover:text-blue-700" href="{{ route('kitchen.view') }}">Kitchen</a> @endif
                @if(auth()->user()->hasRole('waiter')) <a class="hover:text-blue-700" href="{{ route('waiter.view') }}">Waiter</a> @endif
                @if(auth()->user()->hasRole('owner')) <a class="hover:text-blue-700" href="{{ route('dashboard.revenue') }}">Dashboard</a> @endif
            @endauth
            <a class="hover:text-blue-700" href="{{ route('customer.order') }}">Customer order</a>
        </nav>
        @auth
            <details class="relative text-sm text-slate-700">
                <summary class="cursor-pointer rounded-lg border border-slate-300 px-3 py-2">Alerts ({{ auth()->user()->unreadNotifications()->count() }})</summary>
                <div class="absolute right-0 z-20 mt-2 w-72 space-y-2 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
                    @forelse(auth()->user()->unreadNotifications()->latest()->limit(5)->get() as $alert)
                        <p class="border-b border-slate-100 pb-2 text-xs"><strong class="block text-blue-700">{{ $alert->data['title'] }}</strong>{{ $alert->data['message'] }}</p>
                    @empty
                        <p class="text-xs text-slate-500">No new alerts.</p>
                    @endforelse
                </div>
            </details>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Sign out</button>
            </form>
        @endauth
    </div>
</header>
