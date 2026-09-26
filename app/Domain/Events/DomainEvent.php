<?php

namespace App\Domain\Events;

use Illuminate\Support\Carbon;

final readonly class DomainEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $name,
        public int $schemaVersion,
        public string $aggregateType,
        public int $aggregateId,
        public int $aggregateVersion,
        public string $correlationId,
        public array $payload,
        public Carbon $occurredAt,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            $row->id,
            $row->event_name,
            (int) $row->schema_version,
            $row->aggregate_type,
            (int) $row->aggregate_id,
            (int) $row->aggregate_version,
            $row->correlation_id,
            json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR),
            Carbon::parse($row->occurred_at),
        );
    }
}
