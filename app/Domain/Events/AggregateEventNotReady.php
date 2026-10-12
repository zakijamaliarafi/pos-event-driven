<?php

namespace App\Domain\Events;

use RuntimeException;

final class AggregateEventNotReady extends RuntimeException
{
    public function __construct(public readonly string $previousEventId, public readonly string $consumer)
    {
        parent::__construct("Waiting for aggregate event {$previousEventId} consumer {$consumer}.");
    }
}
