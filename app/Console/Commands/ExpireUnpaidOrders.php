<?php

namespace App\Console\Commands;

use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use Illuminate\Console\Command;

final class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Expire cash orders whose stock hold has elapsed';

    public function handle(TransitionOrder $orders): int
    {
        Order::query()
            ->whereIn('status', ['awaiting_inventory', 'waiting_payment'])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => $orders->expire($id));

        return self::SUCCESS;
    }
}
