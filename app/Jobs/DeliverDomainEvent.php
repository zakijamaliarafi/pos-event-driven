<?php

namespace App\Jobs;

use App\Domain\Events\ProcessDomainEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverDomainEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 15, 30];

    public function __construct(public string $eventId)
    {
        $this->onQueue(config('pos.domain_queue', 'domain'));
    }

    public function handle(ProcessDomainEvent $processor): void
    {
        $processor->process($this->eventId);
    }

    public function failed(Throwable $exception): void
    {
        DB::table('domain_outbox')->where('id', $this->eventId)->update([
            'last_error' => $exception->getMessage(),
        ]);
    }
}
