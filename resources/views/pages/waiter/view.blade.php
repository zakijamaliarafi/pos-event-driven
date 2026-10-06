<?php

use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $search = '';

    #[Locked]
    public string $queue = 'ready';

    #[Locked]
    public string $message = '';

    #[Locked]
    public string $refreshedAt = '';

    public bool $showConfirmation = false;

    #[Locked]
    public ?int $confirmationOrderId = null;

    #[Locked]
    public string $confirmationAction = '';

    #[Locked]
    public string $confirmationOrderNumber = '';

    #[Locked]
    public bool $confirmationFailed = false;

    #[Locked]
    public string $confirmationError = '';

    public function mount(): void
    {
        $this->refreshOrders();
    }

    private function authorizeWaiter(): void
    {
        abort_unless(auth()->user()?->hasRole('waiter'), 403);
    }

    #[On('order-view-changed')]
    public function refreshOrders(): void
    {
        $this->authorizeWaiter();
        $this->refreshedAt = now()->timezone(config('app.timezone'))->format('H:i:s');
    }

    public function selectQueue(string $queue): void
    {
        $this->authorizeWaiter();
        abort_unless(in_array($queue, ['ready', 'awaiting_verification'], true), 422);
        $this->queue = $queue;
    }

    public function clearSearch(): void
    {
        $this->authorizeWaiter();
        $this->search = '';
    }

    private function orderDetails(): Builder
    {
        return Order::query()->select('orders.*')
            ->addSelect(['is_paid' => DB::table('cash_payments')->selectRaw('1')->whereColumn('order_id', 'orders.id')->limit(1)])
            ->with(['items.product' => fn ($query) => $query->withTrashed()]);
    }

    private function queueOrders(string $status): Builder
    {
        $query = $this->orderDetails()->where('status', $status)
            ->whereIn('id', DB::table('order_read_models')->select('order_id')->where('status', $status));

        if ($status === 'awaiting_verification') {
            $query->whereNotNull('device_id');
        }

        return $query->orderBy($status === 'ready' ? 'updated_at' : 'created_at')->orderBy('id');
    }

    private function canAct(?Order $order, string $action): bool
    {
        if (! $order) {
            return false;
        }

        return match ($action) {
            'serve' => $order->status === 'ready',
            'reject' => $order->device_id !== null && $order->status === 'awaiting_verification',
            'verify' => $order->device_id !== null && $order->status === 'awaiting_verification'
                && DB::table('stock_reservations')->where('order_id', $order->id)->where('status', 'reserved')->exists(),
            default => false,
        };
    }

    public function openConfirmation(int $orderId, string $action): void
    {
        $this->authorizeWaiter();
        abort_unless(in_array($action, ['verify', 'reject', 'serve'], true), 422);
        $this->resetValidation('confirmation');
        $this->reset('showConfirmation', 'confirmationOrderId', 'confirmationAction', 'confirmationOrderNumber', 'confirmationFailed', 'confirmationError');
        $this->message = '';
        $order = $this->queueOrders($action === 'serve' ? 'ready' : 'awaiting_verification')->find($orderId);

        if (! $order) {
            $this->message = 'Pesanan tidak lagi tersedia dalam antrean. Daftar pesanan telah diperbarui.';
            $this->refreshOrders();
            $this->dispatch('waiter-confirmation-closed');

            return;
        }

        $this->confirmationOrderId = $order->id;
        $this->confirmationOrderNumber = $order->order_number;
        $this->confirmationAction = $action;
        $this->showConfirmation = true;
        $this->dispatch('waiter-confirmation-opened');
    }

    public function closeConfirmation(): void
    {
        $this->authorizeWaiter();
        $this->reset('showConfirmation', 'confirmationOrderId', 'confirmationAction', 'confirmationOrderNumber', 'confirmationFailed', 'confirmationError');
        $this->resetValidation('confirmation');
        $this->dispatch('waiter-confirmation-closed');
    }

    public function serve(int $orderId, TransitionOrder $orders): void
    {
        $this->transitionOrder($orderId, 'serve', $orders);
    }

    public function verify(int $orderId, TransitionOrder $orders): void
    {
        $this->transitionOrder($orderId, 'verify', $orders);
    }

    public function reject(int $orderId, TransitionOrder $orders): void
    {
        $this->transitionOrder($orderId, 'reject', $orders);
    }

    private function transitionOrder(int $orderId, string $action, TransitionOrder $orders): void
    {
        $this->authorizeWaiter();
        abort_unless($this->showConfirmation && $this->confirmationOrderId === $orderId && $this->confirmationAction === $action, 403);
        $this->resetValidation('confirmation');

        try {
            $order = $this->orderDetails()->findOrFail($orderId);
            if ($this->confirmationFailed || ! $this->canAct($order, $action)) {
                throw ValidationException::withMessages(['confirmation' => 'Status pesanan berubah atau tindakan ini belum tersedia. Tutup dialog untuk melihat daftar terbaru.']);
            }

            $order = $orders->{$action === 'serve' ? 'complete' : $action}($orderId);
            $messageKey = match ($action) {
                'verify' => 'verified',
                'reject' => 'rejected',
                default => $order->status === 'completed' ? 'completed' : 'served',
            };
            $this->message = $order->order_number.' — '.__('orders.messages.'.$messageKey);
            $this->closeConfirmation();
            $this->refreshOrders();
        } catch (ValidationException $exception) {
            $this->confirmationFailed = true;
            $this->confirmationError = collect($exception->errors())->flatten()->first();
            $this->addError('confirmation', $this->confirmationError);
            $this->dispatch('waiter-confirmation-invalid');
        } catch (ModelNotFoundException) {
            $this->confirmationFailed = true;
            $this->confirmationError = 'Pesanan tidak ditemukan. Tutup dialog untuk melihat daftar terbaru.';
            $this->addError('confirmation', $this->confirmationError);
            $this->dispatch('waiter-confirmation-invalid');
        }
    }

    public function with(): array
    {
        $this->authorizeWaiter();
        if ($this->confirmationError !== '') {
            $this->addError('confirmation', $this->confirmationError);
        }
        $search = trim(mb_substr($this->search, 0, 255));
        $lanes = [];
        foreach (['ready' => 'Siap disajikan', 'awaiting_verification' => 'Menunggu verifikasi'] as $status => $title) {
            $query = $this->queueOrders($status);
            $count = (clone $query)->count();
            if ($search !== '') {
                $pattern = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn ($query) => $query->where('table_number', 'like', $pattern)
                    ->orWhere('customer_name', 'like', $pattern)->orWhere('order_number', 'like', $pattern));
            }
            $lanes[$status] = ['title' => $title, 'count' => $count, 'orders' => $query->get()];
        }

        $confirmationOrder = $this->confirmationOrderId === null ? null : $this->orderDetails()->find($this->confirmationOrderId);

        return [
            'lanes' => $lanes,
            'hasSearch' => $search !== '',
            'activeCount' => array_sum(array_column($lanes, 'count')),
            'confirmationOrder' => $confirmationOrder,
            'confirmationEligible' => ! $this->confirmationFailed && $this->canAct($confirmationOrder, $this->confirmationAction),
        ];
    }
};
?>

<div class="min-h-screen bg-pos-canvas text-pos-ink" data-waiter-page>
    <x-pos-nav />
    <main class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-widest text-pos-link">Layanan</p>
                <h1 class="text-2xl font-bold sm:text-3xl">Pesanan pramusaji</h1>
                <p class="text-sm text-pos-muted">Periksa pesanan pelanggan dan sajikan pesanan yang sudah siap.</p>
            </div>
            <div class="space-y-2 text-sm">
                <p class="inline-block rounded-full border border-pos-border bg-pos-soft px-3 py-1 font-semibold">{{ $activeCount }} pesanan aktif</p>
                <p class="text-xs text-pos-muted">Terakhir diperbarui <span class="tabular-nums">{{ $refreshedAt }}</span></p>
            </div>
        </header>
        <div role="status" aria-live="polite" aria-atomic="true" class="text-sm font-medium text-pos-link">{{ $message }}</div>
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1"><flux:input wire:model.live.debounce.300ms="search" label="Cari pesanan" placeholder="Meja, nama pelanggan, atau nomor pesanan" maxlength="255" class:input="min-h-11" /></div>
            @if($hasSearch)<flux:button wire:click="clearSearch" data-waiter-clear class="min-h-11">Hapus pencarian</flux:button>@endif
        </div>
        <p wire:loading.delay wire:target="search,clearSearch,refreshOrders" role="status" class="text-sm text-pos-muted">Memuat antrean…</p>
        <div class="grid grid-cols-2 gap-2 lg:hidden" role="group" aria-label="Pilih antrean" data-waiter-queues>
            @foreach($lanes as $status => $lane)
                <button type="button" wire:key="waiter-queue-{{ $status }}" wire:click="selectQueue('{{ $status }}')" wire:loading.attr="disabled" wire:target="selectQueue" aria-pressed="{{ $queue === $status ? 'true' : 'false' }}" aria-controls="waiter-lane-{{ $status }}" data-waiter-queue="{{ $status }}" class="min-h-12 min-w-0 rounded-xl border px-3 py-3 text-sm font-semibold {{ $queue === $status ? 'border-pos-action bg-pos-action text-pos-action-foreground' : 'border-pos-border bg-pos-surface hover:bg-pos-soft' }}">
                    {{ $lane['title'] }} <span class="ml-1 tabular-nums">({{ $lane['count'] }})</span>
                </button>
            @endforeach
        </div>
        <div class="grid gap-6 lg:grid-cols-2" wire:loading.attr="aria-busy" wire:target="search,clearSearch,refreshOrders">
            @foreach($lanes as $status => $lane)
                <section id="waiter-lane-{{ $status }}" wire:key="waiter-lane-{{ $status }}" aria-labelledby="waiter-heading-{{ $status }}" class="min-w-0 {{ $queue === $status ? 'block' : 'hidden' }} lg:block">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-pos-border bg-pos-soft p-4">
                        <h2 id="waiter-heading-{{ $status }}" tabindex="-1" class="text-lg font-bold">{{ $lane['title'] }}</h2>
                        <p class="text-sm tabular-nums">{{ $hasSearch ? $lane['orders']->count().' dari '.$lane['count'] : $lane['count'] }} pesanan</p>
                    </div>
                    <div class="space-y-4">
                        @forelse($lane['orders'] as $order)
                            <x-waiter-order-card :order="$order" :lane="$status" />
                        @empty
                            <div class="space-y-3 rounded-xl border border-dashed border-pos-border-strong bg-pos-surface p-5 text-sm text-pos-muted">
                                @if($lane['count'] > 0 && $hasSearch)
                                    <p>Pesanan tidak ditemukan dalam antrean ini. Coba meja, nama, atau nomor pesanan lain.</p>
                                    <flux:button wire:click="clearSearch" data-waiter-clear class="min-h-11">Hapus pencarian</flux:button>
                                @else
                                    <p>{{ $status === 'ready' ? 'Belum ada pesanan yang siap disajikan.' : 'Tidak ada pesanan pelanggan yang perlu diverifikasi.' }}</p>
                                @endif
                            </div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </main>
    <flux:modal name="waiter-confirmation" wire:model.self="showConfirmation" :dismissible="false" :closable="false" data-waiter-confirmation class="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-lg overflow-hidden rounded-2xl border border-pos-border bg-pos-surface! p-0! text-pos-ink">
        <form wire:submit="{{ $confirmationAction ?: 'verify' }}({{ $confirmationOrderId ?? 0 }})" class="flex max-h-[calc(100dvh-2rem)] flex-col" data-waiter-confirmation-form>
            <div class="shrink-0 border-b border-pos-border p-5">
                <h2 id="waiter-confirmation-heading" tabindex="-1" class="text-xl font-bold">{{ match ($confirmationAction) { 'reject' => 'Tolak pesanan?', 'serve' => 'Tandai sudah disajikan?', default => 'Verifikasi pesanan?' } }}</h2>
                <p id="waiter-confirmation-description" class="mt-2 break-all text-sm text-pos-muted">{{ $confirmationOrderNumber }}</p>
            </div>
            <div class="min-h-0 space-y-4 overflow-y-auto overscroll-contain p-5">
                @error('confirmation')<p role="alert" tabindex="-1" data-waiter-confirmation-error class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-3 text-sm text-pos-danger">{{ $message }}</p>@enderror
                @if($confirmationOrder)
                    <x-waiter-order-card :order="$confirmationOrder" :lane="$confirmationAction === 'serve' ? 'ready' : 'awaiting_verification'" :show-actions="false" />
                    <p class="text-sm leading-relaxed">{{ match ($confirmationAction) { 'reject' => 'Pesanan akan ditolak. Stok yang dicadangkan akan segera dikembalikan.', 'serve' => $confirmationOrder->is_paid ? 'Pastikan semua item sudah disajikan atau diserahkan. Pesanan sudah dibayar dan akan selesai.' : 'Pastikan semua item sudah disajikan atau diserahkan. Pembayaran tunai selanjutnya dicatat oleh kasir.', default => 'Pastikan meja dan semua item sesuai permintaan pelanggan. Pemakaian stok akan diproses sebelum pesanan diterima dapur.' } }}</p>
                @endif
                @if(! $confirmationEligible && $confirmationError === '')<p role="status" tabindex="-1" data-waiter-confirmation-unavailable class="rounded-xl bg-pos-soft p-3 text-sm">{{ $confirmationOrder ? 'Status pesanan berubah atau tindakan ini belum tersedia. Tutup dialog untuk melihat daftar terbaru.' : 'Pesanan tidak ditemukan. Tutup dialog untuk melihat daftar terbaru.' }}</p>@endif
            </div>
            <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-pos-border p-5">
                <flux:button type="button" wire:click="closeConfirmation" wire:loading.attr="disabled" wire:target="verify,reject,serve" class="min-h-11">Kembali</flux:button>
                <flux:button type="submit" :variant="$confirmationAction === 'reject' ? 'danger' : 'primary'" :disabled="! $confirmationEligible" wire:loading.attr="disabled" wire:target="verify,reject,serve" wire:key="waiter-confirm-{{ $confirmationOrderId }}-{{ $confirmationAction }}-{{ $confirmationEligible ? 'ready' : 'blocked' }}" class="min-h-11 whitespace-normal">
                    <span wire:loading.remove wire:target="verify,reject,serve">{{ match ($confirmationAction) { 'reject' => 'Ya, tolak', 'serve' => 'Ya, sudah disajikan', default => 'Ya, verifikasi' } }}</span>
                    <span wire:loading wire:target="verify,reject,serve">{{ match ($confirmationAction) { 'reject' => 'Menolak…', 'serve' => 'Mencatat penyajian…', default => 'Memverifikasi…' } }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
