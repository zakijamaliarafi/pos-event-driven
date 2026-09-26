<?php

namespace App\Domain\Events;

use App\Domain\Inventory\InventoryEventConsumer;
use App\Domain\Orders\OrderEventConsumer;
use App\Domain\Orders\OrderNotificationConsumer;
use App\Domain\Orders\OrderProjectionConsumer;
use Illuminate\Support\Facades\DB;

final class ProcessDomainEvent
{
    public function __construct(
        private InventoryEventConsumer $inventory,
        private OrderEventConsumer $orders,
        private OrderProjectionConsumer $projections,
        private OrderNotificationConsumer $notifications,
    ) {}

    public function process(string $eventId): void
    {
        $row = DB::table('domain_outbox')->find($eventId);

        if ($row === null || $row->processed_at !== null) {
            return;
        }

        $event = DomainEvent::fromRow($row);

        foreach ([$this->inventory, $this->orders, $this->projections, $this->notifications] as $consumer) {
            DB::transaction(function () use ($consumer, $event): void {
                DB::table('domain_outbox')->where('id', $event->id)->lockForUpdate()->first();
                $name = $consumer::class;

                if (DB::table('domain_event_receipts')->where('event_id', $event->id)->where('consumer', $name)->exists()) {
                    return;
                }

                if ($event->aggregateVersion > 1) {
                    $previousEventId = DB::table('domain_outbox')
                        ->where('aggregate_type', $event->aggregateType)
                        ->where('aggregate_id', $event->aggregateId)
                        ->where('aggregate_version', $event->aggregateVersion - 1)
                        ->value('id');

                    if ($previousEventId !== null && ! DB::table('domain_event_receipts')
                        ->where('event_id', $previousEventId)
                        ->where('consumer', $name)
                        ->exists()) {
                        throw new \RuntimeException("Aggregate event {$event->id} arrived before version ".($event->aggregateVersion - 1).'.');
                    }
                }

                $consumer->handle($event);

                DB::table('domain_event_receipts')->insert([
                    'event_id' => $event->id,
                    'consumer' => $name,
                    'processed_at' => now(),
                ]);
            });
        }

        DB::table('domain_outbox')->where('id', $eventId)->update(['processed_at' => now(), 'last_error' => null]);
    }
}
