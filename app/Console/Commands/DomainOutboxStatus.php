<?php

namespace App\Console\Commands;

use App\Domain\Events\DomainEventConsumers;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DomainOutboxStatus extends Command
{
    protected $signature = 'domain:outbox-status {eventId?} {--status=} {--limit=50} {--json}';

    protected $description = 'Inspect domain-event recovery state, errors, blockers, and consumer receipts';

    private const array STATUSES = ['pending', 'retrying', 'blocked', 'quarantined', 'processed'];

    public function handle(): int
    {
        $status = $this->option('status');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (($status !== null && ! in_array($status, self::STATUSES, true)) || $limit === false || $limit < 1 || $limit > 1000) {
            $this->components->error('Status tidak valid atau batas harus berupa bilangan bulat antara 1 dan 1000.');

            return self::FAILURE;
        }

        $query = $this->states();
        if ($id = $this->argument('eventId')) {
            $query->where('id', $id);
            if (! $query->exists()) {
                $this->components->error('Event tidak ditemukan.');

                return self::FAILURE;
            }
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        $rows = $query->orderBy('sequence')->limit($limit)->get();
        $receipts = DB::table('domain_event_receipts')->whereIn('event_id', $rows->pluck('id'))->get()->groupBy('event_id');
        $events = $rows->map(function (object $row) use ($receipts): array {
            $completed = $receipts->get($row->id, collect())->pluck('consumer')->all();

            return [
                'id' => $row->id, 'sequence' => $row->sequence, 'event_name' => $row->event_name, 'status' => $row->status,
                'aggregate_type' => $row->aggregate_type, 'aggregate_id' => $row->aggregate_id,
                'aggregate_version' => $row->aggregate_version, 'correlation_id' => $row->correlation_id,
                'attempts' => $row->attempts, 'failed_cycles' => $row->failed_cycles,
                'active_delivery_id' => $row->active_delivery_id, 'occurred_at' => $row->occurred_at,
                'dispatched_at' => $row->dispatched_at, 'processed_at' => $row->processed_at,
                'next_attempt_at' => $row->next_attempt_at, 'quarantined_at' => $row->quarantined_at,
                'blocked_event_id' => $row->blocked_event_id, 'blocked_consumer' => $row->blocked_consumer,
                'last_error' => $row->last_error, 'last_failed_at' => $row->last_failed_at,
                'last_failed_consumer' => $row->last_failed_consumer, 'completed_consumers' => $completed,
                'missing_consumers' => array_values(array_diff(DomainEventConsumers::NAMES, $completed)),
            ];
        })->all();

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->states()->selectRaw('status, count(*) as total')->groupBy('status')->get() as $count) {
            $counts[$count->status] = (int) $count->total;
        }
        $summary = [
            'counts' => $counts,
            'oldest_pending_age_seconds' => $this->age(DB::table('domain_outbox')->whereNull('processed_at')->min('occurred_at')),
            'oldest_quarantined_age_seconds' => $this->age(DB::table('domain_outbox')->whereNull('processed_at')->min('quarantined_at')),
        ];

        if ($this->option('json')) {
            $this->line(json_encode(['summary' => $summary, 'events' => $events], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Status', 'Jumlah'], collect($counts)->map(fn (int $count, string $name): array => [$name, $count])->values()->all());
            $this->line('Usia event tertunda tertua (detik): '.($summary['oldest_pending_age_seconds'] ?? '-'));
            $this->line('Usia karantina tertua (detik): '.($summary['oldest_quarantined_age_seconds'] ?? '-'));
            foreach ($events as $event) {
                $this->table(['Field', 'Nilai'], collect($event)->map(fn (mixed $value, string $field): array => [
                    $field, is_array($value) ? implode(', ', $value) : ($value ?? '-'),
                ])->values()->all());
            }
        }

        return self::SUCCESS;
    }

    private function states(): Builder
    {
        return DB::query()->fromSub(DB::table('domain_outbox')->select('*')->selectRaw("CASE
            WHEN processed_at IS NOT NULL THEN 'processed'
            WHEN quarantined_at IS NOT NULL THEN 'quarantined'
            WHEN blocked_event_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM domain_event_receipts
                WHERE event_id = domain_outbox.blocked_event_id AND consumer = domain_outbox.blocked_consumer
            ) THEN 'blocked'
            WHEN failed_cycles > 0 THEN 'retrying'
            ELSE 'pending' END AS status"), 'outbox_states');
    }

    private function age(?string $timestamp): ?int
    {
        return $timestamp === null ? null : max(0, now()->getTimestamp() - Carbon::parse($timestamp)->getTimestamp());
    }
}
