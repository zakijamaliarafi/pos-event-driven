<?php

use App\Models\Order;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('staff.orders', function ($user): bool {
    return $user->hasAnyRole(['cashier', 'manager', 'kitchen', 'waiter', 'owner']);
});

Broadcast::channel('customer.order.{orderId}', function ($device, int $orderId): bool {
    return Order::query()
        ->whereKey($orderId)
        ->where('device_id', $device->getAuthIdentifier())
        ->exists();
}, ['guards' => ['pos-device']]);
