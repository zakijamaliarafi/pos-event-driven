<?php

namespace App\Jobs;

use App\Domain\Events\AggregateEventNotReady;
use App\Domain\Events\DomainEventRecovery;
use App\Domain\Events\ProcessDomainEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class DeliverDomainEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 15, 30];

    public ?string $deliveryId = null;

    public function __construct(public string $eventId, ?string $deliveryId = null)
    {
        $this->deliveryId = $deliveryId;
        $this->onQueue(config('pos.domain_queue', 'domain'));
    }

    public function handle(ProcessDomainEvent $processor, DomainEventRecovery $recovery): void
    {
        if ($this->deliveryId === null) {
            return;
        }
        $recovery->validateConfiguration();

        try {
            $processor->process($this->eventId, $this->deliveryId);
        } catch (AggregateEventNotReady $exception) {
            $recovery->block($this->eventId, $this->deliveryId, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->deliveryId !== null) {
            app(DomainEventRecovery::class)->exhausted($this->eventId, $this->deliveryId, $exception);
        }
    }
}
