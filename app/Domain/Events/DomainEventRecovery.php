<?php

namespace App\Domain\Events;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class DomainEventRecovery
{
    public function validateConfiguration(): void
    {
        $cycles = config('pos.domain_max_delivery_cycles');
        $delays = config('pos.domain_recovery_delays');

        if (! is_int($cycles) || $cycles < 1 || $cycles > 100 || ! is_array($delays)
            || ! array_is_list($delays) || count($delays) !== $cycles - 1) {
            throw new InvalidArgumentException('Invalid domain delivery cycle configuration.');
        }

        $previous = 60;
        foreach ($delays as $delay) {
            if (! is_int($delay) || $delay < $previous || $delay > 86400) {
                throw new InvalidArgumentException('Domain recovery delays must be increasing integers between 60 and 86400 seconds.');
            }
            $previous = $delay;
        }

        if ((int) config('queue.connections.redis.retry_after') <= 30) {
            throw new InvalidArgumentException('Redis retry_after must exceed the 30-second domain job timeout.');
        }
    }

    public function eligible(): Builder
    {
        return DB::table('domain_outbox')
            ->whereNull('processed_at')
            ->whereNull('quarantined_at')
            ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinute()))
            ->where(function (Builder $query): void {
                $query->whereNull('blocked_event_id')->orWhereExists(function (Builder $receipts): void {
                    $receipts->selectRaw('1')->from('domain_event_receipts')
                        ->whereColumn('domain_event_receipts.event_id', 'domain_outbox.blocked_event_id')
                        ->whereColumn('domain_event_receipts.consumer', 'domain_outbox.blocked_consumer');
                });
            });
    }

    public function canProcess(?object $row, ?string $deliveryId): bool
    {
        return $row !== null && $row->processed_at === null && $row->quarantined_at === null
            && ($row->next_attempt_at === null || Carbon::parse($row->next_attempt_at)->lessThanOrEqualTo(now()))
            && $row->active_delivery_id === $deliveryId;
    }

    public function recordError(string $eventId, ?string $deliveryId, Throwable $error, ?string $consumer): void
    {
        DB::transaction(function () use ($eventId, $deliveryId, $error, $consumer): void {
            $row = DB::table('domain_outbox')->where('id', $eventId)->lockForUpdate()->first();
            if (! $this->canProcess($row, $deliveryId)) {
                return;
            }

            DB::table('domain_outbox')->where('id', $eventId)->update([
                'last_error' => $error->getMessage(), 'last_failed_at' => now(), 'last_failed_consumer' => $consumer,
            ]);
        });
    }

    public function block(string $eventId, string $deliveryId, AggregateEventNotReady $error): void
    {
        DB::transaction(function () use ($eventId, $deliveryId, $error): void {
            $row = DB::table('domain_outbox')->where('id', $eventId)->lockForUpdate()->first();
            if (! $this->canProcess($row, $deliveryId)) {
                return;
            }

            DB::table('domain_outbox')->where('id', $eventId)->update([
                'blocked_event_id' => $error->previousEventId, 'blocked_consumer' => $error->consumer,
                'active_delivery_id' => null, 'dispatched_at' => null,
            ]);
        });
    }

    public function complete(string $eventId, ?string $deliveryId): void
    {
        DB::transaction(function () use ($eventId, $deliveryId): void {
            $row = DB::table('domain_outbox')->where('id', $eventId)->lockForUpdate()->first();
            if ($this->canProcess($row, $deliveryId) && $this->hasAllReceipts($eventId)) {
                $this->markProcessed($eventId);
            }
        });
    }

    public function exhausted(string $eventId, string $deliveryId, ?Throwable $error): void
    {
        $this->validateConfiguration();

        DB::transaction(function () use ($eventId, $deliveryId, $error): void {
            $row = DB::table('domain_outbox')->where('id', $eventId)->lockForUpdate()->first();
            if (! $this->canProcess($row, $deliveryId)) {
                return;
            }
            if ($this->hasAllReceipts($eventId)) {
                $this->markProcessed($eventId);

                return;
            }

            $failedCycles = (int) $row->failed_cycles + 1;
            $quarantined = $failedCycles >= config('pos.domain_max_delivery_cycles');
            $nextAttempt = $quarantined ? null : now()->addSeconds(config('pos.domain_recovery_delays')[$failedCycles - 1]);
            $consumer = $row->last_failed_consumer ?? $this->missingConsumers($eventId)[0] ?? null;

            DB::table('domain_outbox')->where('id', $eventId)->update([
                'failed_cycles' => $failedCycles, 'active_delivery_id' => null,
                'next_attempt_at' => $nextAttempt, 'quarantined_at' => $quarantined ? now() : null,
                'dispatched_at' => null, 'last_error' => $error?->getMessage() ?? 'Domain event delivery failed.',
                'last_failed_at' => now(), 'last_failed_consumer' => $consumer,
            ]);

            Log::warning('Domain event delivery cycle exhausted.', $this->context($row) + [
                'consumer' => $consumer, 'delivery_id' => $deliveryId, 'failed_cycles' => $failedCycles,
                'next_attempt_at' => $nextAttempt?->toIso8601String(), 'quarantined' => $quarantined,
            ]);
            if ($quarantined) {
                Log::error('Domain event quarantined; operator intervention required.', $this->context($row) + [
                    'consumer' => $consumer, 'failed_cycles' => $failedCycles, 'quarantined' => true,
                ]);
            }
        });
    }

    public function retry(string $eventId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Alasan pengulangan wajib diisi.');
        }

        DB::transaction(function () use ($eventId, $reason): void {
            $row = DB::table('domain_outbox')->where('id', $eventId)->lockForUpdate()->first();
            if ($row === null || $row->processed_at !== null || $row->quarantined_at === null) {
                throw new InvalidArgumentException('Event tidak ditemukan, sudah diproses, atau tidak dikarantina.');
            }

            DB::table('domain_outbox')->where('id', $eventId)->update([
                'failed_cycles' => 0, 'quarantined_at' => null, 'active_delivery_id' => null,
                'next_attempt_at' => now(), 'dispatched_at' => null,
            ]);

            Log::notice('Domain event manually scheduled for retry.', $this->context($row) + [
                'consumer' => $row->last_failed_consumer, 'reason' => trim($reason),
                'previous_failed_cycles' => $row->failed_cycles, 'failed_cycles' => 0, 'quarantined' => false,
            ]);
        });
    }

    /** @return list<string> */
    public function missingConsumers(string $eventId): array
    {
        $completed = DB::table('domain_event_receipts')->where('event_id', $eventId)->pluck('consumer')->all();

        return array_values(array_diff(DomainEventConsumers::NAMES, $completed));
    }

    private function hasAllReceipts(string $eventId): bool
    {
        return $this->missingConsumers($eventId) === [];
    }

    private function markProcessed(string $eventId): void
    {
        DB::table('domain_outbox')->where('id', $eventId)->update([
            'processed_at' => now(), 'last_error' => null, 'last_failed_at' => null, 'last_failed_consumer' => null,
            'active_delivery_id' => null, 'next_attempt_at' => null, 'blocked_event_id' => null, 'blocked_consumer' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function context(object $row): array
    {
        return [
            'event_id' => $row->id, 'event_name' => $row->event_name,
            'aggregate_type' => $row->aggregate_type, 'aggregate_id' => $row->aggregate_id,
            'aggregate_version' => $row->aggregate_version, 'correlation_id' => $row->correlation_id,
        ];
    }
}
