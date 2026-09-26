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
        if (! in_array($event->name, ['InventoryReserved', 'InventoryRejected', 'CashPaymentRecorded'], true)) {
            return;
        }

        $order = Order::query()->lockForUpdate()->findOrFail($event->payload['order_id']);

        if ($event->name === 'InventoryReserved' && $order->status === 'awaiting_inventory') {
            $order->status = 'waiting_payment';
            $this->events->record($order, 'OrderAwaitingPayment', ['order_id' => $order->id], $event->correlationId);
        } elseif ($event->name === 'InventoryRejected' && $order->status === 'awaiting_inventory') {
            $order->status = 'rejected';
            $this->events->record($order, 'OrderRejected', ['order_id' => $order->id, 'reason' => $event->payload['reason']], $event->correlationId);
        } elseif ($event->name === 'CashPaymentRecorded' && $order->status === 'waiting_payment') {
            $order->status = 'pending';
            $this->events->record($order, 'OrderPaid', ['order_id' => $order->id, 'amount' => $order->total_amount], $event->correlationId);
        }
    }
}
