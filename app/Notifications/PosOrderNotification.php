<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

final class PosOrderNotification extends Notification
{
    public function __construct(
        public string $orderNumber,
        public string $message,
        public int $orderId,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'POS order '.$this->orderNumber,
            'message' => $this->message,
            'order_id' => $this->orderId,
        ];
    }
}
