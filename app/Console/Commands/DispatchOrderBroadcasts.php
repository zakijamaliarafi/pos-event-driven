<?php

namespace App\Console\Commands;

use App\Jobs\DeliverOrderBroadcast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DispatchOrderBroadcasts extends Command
{
    protected $signature = 'orders:dispatch-broadcasts {--limit=100}';

    protected $description = 'Queue pending order projection broadcasts for recoverable delivery';

    public function handle(): int
    {
        Cache::lock('order-broadcast-outbox-dispatch', 30)->block(5, function (): void {
            $rows = DB::table('order_broadcast_outbox')
                ->whereNull('sent_at')
                ->where(function ($query): void {
                    $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinute());
                })
                ->orderBy('sequence')
                ->limit(max(1, min(1000, (int) $this->option('limit'))))
                ->get();

            foreach ($rows as $row) {
                DeliverOrderBroadcast::dispatch($row->id)->onConnection('redis');
                DB::table('order_broadcast_outbox')->where('id', $row->id)->update([
                    'dispatched_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                ]);
            }

            $this->components->info("Queued {$rows->count()} order broadcasts.");
        });

        return self::SUCCESS;
    }
}
