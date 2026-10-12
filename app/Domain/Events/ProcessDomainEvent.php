<?php

namespace App\Domain\Events;

use App\Domain\Inventory\InventoryEventConsumer;
use App\Domain\Orders\OrderEventConsumer;
use App\Domain\Orders\OrderNotificationConsumer;
use App\Domain\Orders\OrderProjectionConsumer;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessDomainEvent
{
    public function __construct(
        private InventoryEventConsumer $inventory,
        private OrderEventConsumer $orders,
        private OrderProjectionConsumer $projections,
        private OrderNotificationConsumer $notifications,
        private DomainEventRecovery $recovery,
    ) {}

    public function process(string $eventId, ?string $deliveryId = null): void
    {
        $row = DB::transaction(fn () => DB::table('domain_outbox')->where('id', $eventId)->lockForUpdate()->first());
        if (! $this->recovery->canProcess($row, $deliveryId)) {
            return;
        }

        $consumerName = null;
        try {
            $event = DomainEvent::fromRow($row);

            foreach ([$this->inventory, $this->orders, $this->projections, $this->notifications] as $consumer) {
                $consumerName = $consumer::class;
                $continue = DB::transaction(function () use ($consumer, $event, $deliveryId): bool {
                    $current = DB::table('domain_outbox')->where('id', $event->id)->lockForUpdate()->first();
                    if (! $this->recovery->canProcess($current, $deliveryId)) {
                        return false;
                    }
                    $name = $consumer::class;

                    if (DB::table('domain_event_receipts')->where('event_id', $event->id)->where('consumer', $name)->exists()) {
                        return true;
                    }

                    if ($event->aggregateVersion > 1) {
                        $previousEventId = DB::table('domain_outbox')
                            ->where('aggregate_type', $event->aggregateType)
                            ->where('aggregate_id', $event->aggregateId)
                            ->where('aggregate_version', $event->aggregateVersion - 1)
                            ->value('id');

                        if ($previousEventId !== null && ! DB::table('domain_event_receipts')
                            ->where('event_id', $previousEventId)->where('consumer', $name)->exists()) {
                            throw new AggregateEventNotReady($previousEventId, $name);
                        }
                    }

                    $consumer->handle($event);
                    DB::table('domain_event_receipts')->insert([
                        'event_id' => $event->id, 'consumer' => $name, 'processed_at' => now(),
                    ]);

                    return true;
                });

                if (! $continue) {
                    return;
                }
            }

            $this->recovery->complete($eventId, $deliveryId);
        } catch (AggregateEventNotReady $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->recovery->recordError($eventId, $deliveryId, $exception, $consumerName);

            throw $exception;
        }
    }
}
