<?php

namespace App\Domain\Orders;

use App\Domain\Events\RecordDomainEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransitionOrder
{
    public function __construct(private RecordDomainEvent $events) {}

    public function startPreparation(int $orderId): Order
    {
        return $this->transition($orderId, 'pending', 'preparing', 'PreparationStarted');
    }

    public function markReady(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->status !== 'preparing' || DB::table('stock_reservations')->where('order_id', $orderId)->where('status', 'reserved')->exists()) {
                throw ValidationException::withMessages(['order' => 'Preparation or stock consumption is still pending.']);
            }

            $order->status = 'ready';
            $this->events->record($order, 'OrderReady', ['order_id' => $order->id, 'order_number' => $order->order_number]);

            return $order;
        });
    }

    public function complete(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->status !== 'ready') {
                throw ValidationException::withMessages(['order' => 'Only ready orders can be served.']);
            }

            $isPaid = DB::table('cash_payments')->where('order_id', $orderId)->exists();
            $order->status = $isPaid ? 'completed' : 'awaiting_payment';
            $this->events->record($order, $isPaid ? 'OrderCompleted' : 'OrderServed', ['order_id' => $order->id, 'order_number' => $order->order_number]);

            return $order;
        });
    }

    public function verify(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->device_id === null || $order->status !== 'awaiting_verification' || ! DB::table('stock_reservations')->where('order_id', $orderId)->where('status', 'reserved')->exists()) {
                throw ValidationException::withMessages(['order' => 'This customer order is not ready for verification.']);
            }

            $order->status = 'verifying';
            $this->events->record($order, 'OrderVerified', ['order_id' => $order->id, 'order_number' => $order->order_number]);

            return $order;
        });
    }

    public function reject(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->device_id === null || $order->status !== 'awaiting_verification') {
                throw ValidationException::withMessages(['order' => 'This customer order can no longer be rejected.']);
            }

            $order->status = 'rejected';
            $this->events->record($order, 'OrderRejected', ['order_id' => $order->id, 'reason' => 'Rejected by waiter.']);

            return $order;
        });
    }

    public function cancel(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            $cancellable = $order->device_id === null
                ? in_array($order->status, ['awaiting_inventory', 'waiting_payment'], true)
                : in_array($order->status, ['awaiting_inventory', 'awaiting_verification'], true);

            if (! $cancellable || DB::table('cash_payments')->where('order_id', $orderId)->exists()) {
                throw ValidationException::withMessages(['order' => 'This order can no longer be cancelled.']);
            }

            $order->status = 'cancelled';
            $this->events->record($order, 'OrderCancelled', ['order_id' => $order->id]);

            return $order;
        });
    }

    public function expire(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $order = Order::query()->lockForUpdate()->find($orderId);

            if (! $order || $order->device_id !== null || ! in_array($order->status, ['awaiting_inventory', 'waiting_payment'], true) || ! $order->expires_at?->isPast() || DB::table('cash_payments')->where('order_id', $orderId)->exists()) {
                return;
            }

            $order->status = 'expired';
            $this->events->record($order, 'OrderExpired', ['order_id' => $order->id]);
        });
    }

    public function backfillCustomerVerification(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $order = Order::query()->lockForUpdate()->find($orderId);

            if (! $order || $order->device_id === null || DB::table('cash_payments')->where('order_id', $orderId)->exists()) {
                return false;
            }

            if ($order->status === 'waiting_payment') {
                $order->status = 'awaiting_verification';
                $eventName = 'OrderAwaitingVerification';
            } elseif ($order->status === 'awaiting_inventory' && $order->expires_at !== null) {
                $eventName = 'CustomerOrderDeadlineRemoved';
            } else {
                return false;
            }

            $order->expires_at = null;
            $this->events->record($order, $eventName, ['order_id' => $order->id]);

            return true;
        });
    }

    private function transition(int $orderId, string $from, string $to, string $eventName): Order
    {
        return DB::transaction(function () use ($orderId, $from, $to, $eventName): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->status !== $from) {
                throw ValidationException::withMessages(['order' => "Only {$from} orders can move to {$to}."]);
            }

            $order->status = $to;
            $this->events->record($order, $eventName, ['order_id' => $order->id, 'order_number' => $order->order_number]);

            return $order;
        });
    }
}
