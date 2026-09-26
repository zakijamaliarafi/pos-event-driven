<?php

namespace App\Console\Commands;

use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('orders:backfill-customer-verification')]
#[Description('Move active unpaid customer orders to waiter verification and clear their deadlines')]
final class BackfillCustomerOrderVerification extends Command
{
    public function handle(TransitionOrder $orders): int
    {
        $updated = 0;

        Order::query()
            ->whereNotNull('device_id')
            ->where(fn ($query) => $query->where('status', 'waiting_payment')
                ->orWhere(fn ($query) => $query->where('status', 'awaiting_inventory')->whereNotNull('expires_at')))
            ->whereNotIn('id', DB::table('cash_payments')->select('order_id'))
            ->select('id')
            ->chunkById(100, function ($candidates) use ($orders, &$updated): void {
                foreach ($candidates as $candidate) {
                    if ($orders->backfillCustomerVerification($candidate->id)) {
                        $updated++;
                    }
                }
            });

        $this->components->info("Updated {$updated} customer orders.");

        return self::SUCCESS;
    }
}
