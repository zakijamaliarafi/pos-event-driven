<?php

return [
    'currency' => env('POS_CURRENCY', 'IDR'),
    'unpaid_reservation_minutes' => 15,
    'domain_queue' => env('POS_DOMAIN_QUEUE', 'domain'),
    'domain_max_delivery_cycles' => 3,
    'domain_recovery_delays' => [60, 300],
    'broadcast_queue' => env('POS_BROADCAST_QUEUE', 'default'),
];
