<?php

use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $message = '';

    #[Locked]
    public array $orderErrors = [];

    #[Locked]
    public string $refreshedAt = '';

    public function mount(): void
    {
        $this->refreshOrders();
    }

    #[On('order-view-changed')]
    public function refreshOrders(): void
    {
        abort_unless(auth()->user()?->hasRole('kitchen'), 403);
        $this->refreshedAt = now()->timezone(config('app.timezone'))->format('H:i:s');
    }

    public function start(int $orderId, TransitionOrder $orders): void
    {
        $this->transitionKitchenOrder($orderId, $orders, 'startPreparation', 'preparation_started');
    }

    public function ready(int $orderId, TransitionOrder $orders): void
    {
        $this->transitionKitchenOrder($orderId, $orders, 'markReady', 'marked_ready');
    }

    private function transitionKitchenOrder(int $orderId, TransitionOrder $orders, string $method, string $message): void
    {
        abort_unless(auth()->user()?->hasRole('kitchen'), 403);
        $this->message = '';
        unset($this->orderErrors[$orderId]);

        try {
            $order = $orders->{$method}($orderId);
            $this->message = $order->order_number.' — '.__('orders.messages.'.$message);
            $this->refreshOrders();
        } catch (ValidationException $exception) {
            $this->orderErrors[$orderId] = collect($exception->errors())->flatten()->first();
        } catch (ModelNotFoundException) {
            $this->orderErrors[$orderId] = 'Pesanan tidak ditemukan. Muat ulang antrean.';
        }
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->hasRole('kitchen'), 403);

        $activeStatuses = ['pending', 'preparing', 'ready'];
        $items = ['items.product' => fn ($query) => $query->withTrashed()];
        $active = Order::query()->with($items)
            ->whereIn('id', DB::table('order_read_models')->select('order_id')->whereIn('status', $activeStatuses))
            ->whereIn('status', $activeStatuses)
            ->orderBy('created_at')->orderBy('id')->get();

        $serviceEvents = DB::table('domain_outbox')
            ->select('aggregate_id')->selectRaw('MIN(occurred_at) AS served_at')
            ->where('aggregate_type', (new Order)->getMorphClass())
            ->whereIn('event_name', ['OrderServed', 'OrderCompleted'])
            ->groupBy('aggregate_id');

        $served = Order::query()->select('orders.*', 'service_events.served_at')->with($items)
            ->leftJoinSub($serviceEvents, 'service_events', 'service_events.aggregate_id', '=', 'orders.id')
            ->whereIn('orders.id', DB::table('order_read_models')->select('order_id')->whereIn('status', ['awaiting_payment', 'completed']))
            ->whereIn('orders.status', ['awaiting_payment', 'completed'])
            ->orderByRaw('service_events.served_at IS NULL')
            ->orderByDesc('service_events.served_at')->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->limit(30)->get();

        return [
            'activeCount' => $active->count(),
            'lanes' => [
                'pending' => ['title' => 'Menunggu', 'empty' => 'Tidak ada pesanan yang menunggu penyiapan.', 'orders' => $active->where('status', 'pending')->values()],
                'preparing' => ['title' => 'Disiapkan', 'empty' => 'Tidak ada pesanan yang sedang disiapkan.', 'orders' => $active->where('status', 'preparing')->values()],
                'ready' => ['title' => 'Siap disajikan', 'empty' => 'Belum ada pesanan yang siap disajikan.', 'orders' => $active->where('status', 'ready')->values()],
                'served' => ['title' => 'Disajikan', 'empty' => 'Belum ada riwayat pesanan yang disajikan.', 'orders' => $served],
            ],
        ];
    }
};
?>

<div class="kitchen-page min-h-screen bg-pos-canvas text-pos-ink" data-kitchen-board>
    <x-pos-nav />
    <main class="kitchen-main px-4 py-6 sm:px-6">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold sm:text-3xl">Antrean dapur</h1>
                <span class="rounded-full border border-pos-border bg-pos-soft px-3 py-1 text-sm font-semibold">{{ $activeCount }} pesanan aktif</span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <p class="text-xs text-pos-muted">Terakhir diperbarui <span class="font-semibold tabular-nums">{{ $refreshedAt }}</span></p>
                <flux:button wire:click="refreshOrders" wire:loading.attr="disabled" wire:target="refreshOrders" class="min-h-11">Muat ulang</flux:button>
            </div>
        </div>
        <div role="status" aria-live="polite" aria-atomic="true" class="mb-3 text-sm font-medium text-pos-link">{{ $message }}</div>
        <p wire:loading.delay wire:target="refreshOrders" role="status" class="mb-3 text-sm text-pos-muted">Memuat antrean…</p>
        @foreach($orderErrors as $errorId => $error)
            @if(! collect($lanes)->pluck('orders')->flatten()->contains('id', $errorId))
                <p role="alert" class="mb-3 rounded-xl border border-pos-danger-border bg-pos-danger-soft p-3 text-sm text-pos-danger">{{ $error }}</p>
            @endif
        @endforeach
        <div class="kitchen-grid grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
            @foreach($lanes as $status => $lane)
                <section wire:key="kitchen-lane-{{ $status }}" data-kitchen-lane="{{ $status }}" class="kitchen-lane min-w-0" aria-labelledby="kitchen-heading-{{ $status }}">
                    <header class="kitchen-lane-header mb-4 flex items-center justify-between gap-2 rounded-xl px-4 py-3">
                        <div class="min-w-0">
                            <h2 id="kitchen-heading-{{ $status }}" tabindex="-1" class="text-lg font-bold">{{ $lane['title'] }}</h2>
                            @if($status === 'served')<p class="text-xs">30 terakhir · hanya lihat</p>@endif
                        </div>
                        <span class="rounded-lg bg-white/20 px-2.5 py-1 font-bold tabular-nums" aria-label="{{ $lane['orders']->count() }} pesanan">{{ $lane['orders']->count() }}</span>
                    </header>
                    <div class="kitchen-lane-list space-y-4" data-kitchen-scroll="{{ $status }}" tabindex="0" role="region" aria-label="Daftar pesanan {{ $lane['title'] }}">
                        @forelse($lane['orders'] as $order)
                            <x-kitchen-order-card :order="$order" :lane="$status" :error="$orderErrors[$order->id] ?? null" />
                        @empty
                            <p class="rounded-xl border border-dashed border-pos-border-strong bg-pos-surface p-5 text-sm leading-relaxed text-pos-muted">{{ $lane['empty'] }}</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </main>
</div>
