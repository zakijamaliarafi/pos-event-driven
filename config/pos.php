<?php

return [
    'currency' => env('POS_CURRENCY', 'IDR'),
    'unpaid_reservation_minutes' => 15,
    'domain_queue' => env('POS_DOMAIN_QUEUE', 'domain'),
    'broadcast_queue' => env('POS_BROADCAST_QUEUE', 'default'),
];
