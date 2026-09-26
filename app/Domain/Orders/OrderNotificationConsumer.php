<?php

namespace App\Domain\Orders;

use App\Domain\Events\DomainEvent;
use App\Models\Order;
use App\Models\User;
use App\Notifications\PosOrderNotification;
use Illuminate\Support\Facades\Notification;

final class OrderNotificationConsumer
{
    public function handle(DomainEvent $event): void
    {
        [$role, $message] = match ($event->name) {
            'OrderAwaitingPayment' => ['cashier', 'A customer order is ready for cash payment.'],
            'OrderPaid' => ['kitchen', 'A paid order is ready for preparation.'],
            'OrderReady' => ['waiter', 'An order is ready for service.'],
            'InventoryRejected' => ['manager', 'An order was rejected because stock is unavailable.'],
            default => [null, null],
        };

        if ($role === null) {
            return;
        }

        $order = Order::findOrFail($event->payload['order_id']);
        Notification::send(
            User::query()->whereHas('roles', fn ($query) => $query->where('name', $role)->where('guard_name', 'web'))->get(),
            new PosOrderNotification($order->order_number, $message, $order->id),
        );
    }
}
