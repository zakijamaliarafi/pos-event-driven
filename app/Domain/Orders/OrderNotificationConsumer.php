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
            'OrderAwaitingPayment' => ['cashier', __('orders.notifications.cashier_payment')],
            'OrderAwaitingVerification' => ['waiter', __('orders.notifications.waiter_verification')],
            'OrderQueuedForKitchen' => ['kitchen', __('orders.notifications.kitchen_verified')],
            'OrderServed' => ['cashier', __('orders.notifications.cashier_after_service')],
            'OrderPaid' => ['kitchen', __('orders.notifications.kitchen_paid')],
            'OrderReady' => ['waiter', __('orders.notifications.waiter_ready')],
            'InventoryRejected' => ['manager', __('orders.notifications.stock_unavailable')],
            default => [null, null],
        };

        if ($role === null || ($event->name === 'OrderPaid' && ($event->payload['from_status'] ?? null) === 'awaiting_payment')) {
            return;
        }

        $order = Order::findOrFail($event->payload['order_id']);
        Notification::send(
            User::query()->whereHas('roles', fn ($query) => $query->where('name', $role)->where('guard_name', 'web'))->get(),
            new PosOrderNotification($order->order_number, $message, $order->id),
        );
    }
}
