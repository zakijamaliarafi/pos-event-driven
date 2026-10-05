<?php

namespace App\Domain\Inventory;

use App\Domain\Catalog\ProductAvailability;
use App\Domain\Events\DomainEvent;
use App\Domain\Events\RecordDomainEvent;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class InventoryEventConsumer
{
    public function __construct(private RecordDomainEvent $events) {}

    public function handle(DomainEvent $event): void
    {
        match ($event->name) {
            'OrderSubmitted' => $this->reserve($event),
            'OrderVerified', 'PreparationStarted' => $this->finishReservation($event, 'consumed'),
            'OrderCancelled', 'OrderExpired', 'OrderRejected' => $this->finishReservation($event, 'released'),
            default => null,
        };
    }

    private function reserve(DomainEvent $event): void
    {
        $order = Order::query()->lockForUpdate()->findOrFail($event->payload['order_id']);

        if ($order->status !== 'awaiting_inventory') {
            return;
        }

        $order->load('items');
        $ingredientQuantities = [];
        $reason = $order->items->isEmpty() ? 'Recipe is unavailable.' : null;

        foreach ($order->items as $item) {
            if (empty($item->recipe_snapshot)) {
                $reason = 'Recipe is unavailable.';
                break;
            }

            foreach ($item->recipe_snapshot as $recipe) {
                $id = $recipe['inventory_item_id'];
                $quantity = ProductAvailability::hundredths($recipe['quantity_required']);

                if ($quantity <= 0) {
                    $reason = 'Recipe is unavailable.';
                    break 2;
                }

                $ingredientQuantities[$id] = ($ingredientQuantities[$id] ?? 0) + $quantity * $item->quantity;
            }
        }

        ksort($ingredientQuantities);

        $ingredients = InventoryItem::whereIn('id', array_keys($ingredientQuantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($reason === null) {
            foreach ($ingredientQuantities as $id => $quantity) {
                $ingredient = $ingredients->get($id);

                if (! $ingredient || ProductAvailability::hundredths($ingredient->current_stock) - ProductAvailability::hundredths($ingredient->reserved_stock) < $quantity) {
                    $reason = 'Ingredient stock is unavailable.';
                    break;
                }
            }
        }

        if ($reason !== null) {
            $this->events->record($order, 'InventoryRejected', ['order_id' => $order->id, 'reason' => $reason], $event->correlationId);

            return;
        }

        foreach ($ingredientQuantities as $id => $quantity) {
            $ingredient = $ingredients->get($id);
            $ingredient->reserved_stock = (ProductAvailability::hundredths($ingredient->reserved_stock) + $quantity) / 100;
            $ingredient->save();
            $this->storeReservation($order->id, 'ingredient', $id, $quantity / 100);
        }

        $this->events->record($order, 'InventoryReserved', ['order_id' => $order->id], $event->correlationId);
    }

    private function storeReservation(int $orderId, string $type, int $id, float $quantity): void
    {
        DB::table('stock_reservations')->insert([
            'order_id' => $orderId,
            'resource_type' => $type,
            'resource_id' => $id,
            'quantity' => $quantity,
            'status' => 'reserved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function finishReservation(DomainEvent $event, string $newStatus): void
    {
        $reservations = DB::table('stock_reservations')
            ->where('order_id', $event->payload['order_id'])
            ->where('status', 'reserved')
            ->orderBy('resource_type')
            ->orderBy('resource_id')
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $resource = $reservation->resource_type === 'product'
                ? Product::withTrashed()->lockForUpdate()->findOrFail($reservation->resource_id)
                : InventoryItem::lockForUpdate()->findOrFail($reservation->resource_id);

            $quantity = (float) $reservation->quantity;
            $resource->reserved_stock = round((float) $resource->reserved_stock - $quantity, 2);

            if ($newStatus === 'consumed') {
                $resource->current_stock = round((float) $resource->current_stock - $quantity, 2);
            }

            if ($reservation->resource_type === 'product') {
                $resource->is_available = $resource->current_stock > 0;
            }

            $resource->save();
            DB::table('stock_reservations')->where('id', $reservation->id)->update(['status' => $newStatus, 'updated_at' => now()]);
        }

        if ($reservations->isNotEmpty()) {
            $order = Order::query()->lockForUpdate()->findOrFail($event->payload['order_id']);
            $this->events->record($order, $newStatus === 'consumed' ? 'InventoryConsumed' : 'InventoryReleased', ['order_id' => $order->id], $event->correlationId);
        }
    }
}
