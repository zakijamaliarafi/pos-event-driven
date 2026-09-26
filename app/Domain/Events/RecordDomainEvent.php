<?php

namespace App\Domain\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordDomainEvent
{
    /**
     * Call within the same database transaction as the aggregate change.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(Model $aggregate, string $name, array $payload, ?string $correlationId = null): string
    {
        $aggregate->version = (int) $aggregate->version + 1;
        $aggregate->save();

        $id = (string) Str::uuid();

        DB::table('domain_outbox')->insert([
            'id' => $id,
            'event_name' => $name,
            'schema_version' => 1,
            'aggregate_type' => $aggregate->getMorphClass(),
            'aggregate_id' => $aggregate->getKey(),
            'aggregate_version' => $aggregate->version,
            'correlation_id' => $correlationId ?? $id,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);

        return $id;
    }
}
