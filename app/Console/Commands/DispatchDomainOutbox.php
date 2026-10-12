<?php

namespace App\Console\Commands;

use App\Domain\Events\DomainEventRecovery;
use App\Jobs\DeliverDomainEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

final class DispatchDomainOutbox extends Command
{
    protected $signature = 'domain:dispatch-outbox {--limit=100}';

    protected $description = 'Send eligible domain events to Redis with bounded failure recovery';

    public function handle(DomainEventRecovery $recovery): int
    {
        $recovery->validateConfiguration();

        Cache::lock('domain-outbox-dispatch', 30)->block(5, function () use ($recovery): void {
            $ids = $recovery->eligible()->orderBy('sequence')
                ->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');
            $queued = 0;

            foreach ($ids as $id) {
                $queued += DB::transaction(function () use ($recovery, $id): int {
                    $row = DB::table('domain_outbox')->where('id', $id)->lockForUpdate()->first();
                    if ($row === null || ! $recovery->eligible()->where('id', $id)->exists()) {
                        return 0;
                    }

                    $deliveryId = $row->active_delivery_id ?? (string) Str::uuid();
                    $job = new DeliverDomainEvent($id, $deliveryId);
                    $job->onConnection('redis')->beforeCommit();
                    Queue::connection('redis')->push($job, '', config('pos.domain_queue', 'domain'));

                    DB::table('domain_outbox')->where('id', $id)->update([
                        'active_delivery_id' => $deliveryId, 'dispatched_at' => now(),
                        'attempts' => DB::raw('attempts + 1'), 'next_attempt_at' => null,
                        'blocked_event_id' => null, 'blocked_consumer' => null,
                    ]);

                    return 1;
                });
            }

            $this->components->info("Queued {$queued} domain events.");
        });

        return self::SUCCESS;
    }
}
