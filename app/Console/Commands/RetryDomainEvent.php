<?php

namespace App\Console\Commands;

use App\Domain\Events\DomainEventRecovery;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RetryDomainEvent extends Command
{
    protected $signature = 'domain:retry-event {eventId} {--reason=}';

    protected $description = 'Resume one quarantined domain event while preserving consumer receipts';

    public function handle(DomainEventRecovery $recovery): int
    {
        try {
            $recovery->retry((string) $this->argument('eventId'), (string) $this->option('reason'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Event dijadwalkan untuk diproses ulang. Receipt yang sudah selesai dipertahankan.');

        return self::SUCCESS;
    }
}
