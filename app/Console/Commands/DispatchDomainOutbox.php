<?php

namespace App\Console\Commands;

use App\Jobs\DeliverDomainEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DispatchDomainOutbox extends Command
{
    protected $signature = 'domain:dispatch-outbox {--limit=100}';

    protected $description = 'Send pending domain events to the durable Redis queue';

    public function handle(): int
    {
        Cache::lock('domain-outbox-dispatch', 30)->block(5, function (): void {
            $rows = DB::table('domain_outbox')
                ->whereNull('processed_at')
                ->where(function ($query): void {
                    $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinute());
                })
                ->orderBy('sequence')
                ->limit(max(1, min(1000, (int) $this->option('limit'))))
                ->get();

            foreach ($rows as $row) {
                DeliverDomainEvent::dispatch($row->id)->onConnection('redis');
                DB::table('domain_outbox')->where('id', $row->id)->update([
                    'dispatched_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                ]);
            }

            $this->components->info("Queued {$rows->count()} domain events.");
        });

        return self::SUCCESS;
    }
}
