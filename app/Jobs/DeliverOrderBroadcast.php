<?php

namespace App\Jobs;

use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeliverOrderBroadcast implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 15, 30];

    public function __construct(public string $intentId)
    {
        $this->onQueue(config('pos.broadcast_queue', 'default'));
    }

    public function handle(Factory $broadcasters): void
    {
        $error = DB::transaction(function () use ($broadcasters): ?Throwable {
            $intent = DB::table('order_broadcast_outbox')->where('id', $this->intentId)->lockForUpdate()->first();

            if ($intent === null || $intent->sent_at !== null) {
                return null;
            }

            try {
                $broadcasters->connection($intent->broadcast_connection)->broadcast(
                    json_decode($intent->channels, true, flags: JSON_THROW_ON_ERROR),
                    'order.changed',
                    json_decode($intent->payload, true, flags: JSON_THROW_ON_ERROR),
                );
            } catch (Throwable $exception) {
                DB::table('order_broadcast_outbox')->where('id', $this->intentId)->update([
                    'last_error' => $exception->getMessage(),
                ]);

                Log::warning('Order broadcast publication failed.', [
                    'intent_id' => $this->intentId,
                    'order_id' => $intent->order_id,
                    'projection_version' => $intent->projection_version,
                    'exception' => $exception,
                ]);

                return $exception;
            }

            DB::table('order_broadcast_outbox')->where('id', $this->intentId)->update([
                'sent_at' => now(),
                'last_error' => null,
            ]);

            return null;
        });

        if ($error !== null) {
            throw $error;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('order_broadcast_outbox')->where('id', $this->intentId)->whereNull('sent_at')->update([
            'last_error' => $exception?->getMessage() ?? 'Order broadcast delivery failed.',
        ]);
    }
}
