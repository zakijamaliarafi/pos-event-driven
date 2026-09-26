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
        return $this->transition($orderId, 'ready', 'completed', 'OrderCompleted');
    }

    public function cancel(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if (! in_array($order->status, ['awaiting_inventory', 'waiting_payment'], true) || DB::table('cash_payments')->where('order_id', $orderId)->exists()) {
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

            if (! $order || ! in_array($order->status, ['awaiting_inventory', 'waiting_payment'], true) || ! $order->expires_at?->isPast() || DB::table('cash_payments')->where('order_id', $orderId)->exists()) {
                return;
            }

            $order->status = 'expired';
            $this->events->record($order, 'OrderExpired', ['order_id' => $order->id]);
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
