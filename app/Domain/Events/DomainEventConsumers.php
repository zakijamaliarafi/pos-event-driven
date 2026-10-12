<?php

namespace App\Domain\Events;

use App\Domain\Inventory\InventoryEventConsumer;
use App\Domain\Orders\OrderEventConsumer;
use App\Domain\Orders\OrderNotificationConsumer;
use App\Domain\Orders\OrderProjectionConsumer;

final class DomainEventConsumers
{
    public const array NAMES = [
        InventoryEventConsumer::class,
        OrderEventConsumer::class,
        OrderProjectionConsumer::class,
        OrderNotificationConsumer::class,
    ];
}
