<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderViewChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public string $status,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('staff.orders')];

        if (Order::query()->whereKey($this->orderId)->whereNotNull('device_id')->exists()) {
            $channels[] = new PrivateChannel('customer.order.'.$this->orderId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'status' => $this->status,
        ];
    }
}
