<?php

namespace App\Domain\Inventory;

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
            'PreparationStarted' => $this->finishReservation($event, 'consumed'),
            'OrderCancelled', 'OrderExpired' => $this->finishReservation($event, 'released'),
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
        $productQuantities = [];
        $ingredientQuantities = [];

        foreach ($order->items as $item) {
            $productQuantities[$item->product_id] = ($productQuantities[$item->product_id] ?? 0) + $item->quantity;

            foreach ($item->recipe_snapshot ?? [] as $recipe) {
                $id = $recipe['inventory_item_id'];
                $ingredientQuantities[$id] = round(($ingredientQuantities[$id] ?? 0) + $recipe['quantity_required'] * $item->quantity, 2);
            }
        }

        ksort($productQuantities);
        ksort($ingredientQuantities);

        $products = Product::withTrashed()->whereIn('id', array_keys($productQuantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $ingredients = InventoryItem::whereIn('id', array_keys($ingredientQuantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $reason = null;

        foreach ($productQuantities as $id => $quantity) {
            $product = $products->get($id);

            if (! $product || ! $product->is_available || $product->current_stock - $product->reserved_stock < $quantity) {
                $reason = 'Product stock is unavailable.';
                break;
            }
        }

        if ($reason === null) {
            foreach ($ingredientQuantities as $id => $quantity) {
                $ingredient = $ingredients->get($id);

                if (! $ingredient || (float) $ingredient->current_stock - (float) $ingredient->reserved_stock + 0.00001 < $quantity) {
                    $reason = 'Ingredient stock is unavailable.';
                    break;
                }
            }
        }

        if ($reason !== null) {
            $this->events->record($order, 'InventoryRejected', ['order_id' => $order->id, 'reason' => $reason], $event->correlationId);

            return;
        }

        foreach ($productQuantities as $id => $quantity) {
            $product = $products->get($id);
            $product->reserved_stock += $quantity;
            $product->save();
            $this->storeReservation($order->id, 'product', $id, $quantity);
        }

        foreach ($ingredientQuantities as $id => $quantity) {
            $ingredient = $ingredients->get($id);
            $ingredient->reserved_stock = round((float) $ingredient->reserved_stock + $quantity, 2);
            $ingredient->save();
            $this->storeReservation($order->id, 'ingredient', $id, $quantity);
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
