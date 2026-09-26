<?php

namespace App\Domain\Orders;

use App\Domain\Events\DomainEvent;
use App\Domain\Events\RecordDomainEvent;
use App\Models\Order;

final class OrderEventConsumer
{
    public function __construct(private RecordDomainEvent $events) {}

    public function handle(DomainEvent $event): void
    {
        if (! in_array($event->name, ['InventoryReserved', 'InventoryRejected', 'InventoryConsumed', 'CashPaymentRecorded'], true)) {
            return;
        }

        $order = Order::query()->lockForUpdate()->findOrFail($event->payload['order_id']);

        if ($event->name === 'InventoryReserved' && $order->status === 'awaiting_inventory') {
            $order->status = $order->device_id === null ? 'waiting_payment' : 'awaiting_verification';
            $this->events->record($order, $order->device_id === null ? 'OrderAwaitingPayment' : 'OrderAwaitingVerification', ['order_id' => $order->id], $event->correlationId);
        } elseif ($event->name === 'InventoryRejected' && $order->status === 'awaiting_inventory') {
            $order->status = 'rejected';
            $this->events->record($order, 'OrderRejected', ['order_id' => $order->id, 'reason' => $event->payload['reason']], $event->correlationId);
        } elseif ($event->name === 'InventoryConsumed' && $order->status === 'verifying') {
            $order->status = 'pending';
            $this->events->record($order, 'OrderQueuedForKitchen', ['order_id' => $order->id], $event->correlationId);
        } elseif ($event->name === 'CashPaymentRecorded' && in_array($order->status, ['waiting_payment', 'awaiting_payment'], true)) {
            $fromStatus = $order->status;
            $order->status = $fromStatus === 'waiting_payment' ? 'pending' : 'completed';
            $this->events->record($order, 'OrderPaid', ['order_id' => $order->id, 'amount' => $order->total_amount, 'from_status' => $fromStatus], $event->correlationId);
        }
    }
}
