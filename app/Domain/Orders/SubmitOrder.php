<?php

namespace App\Domain\Orders;

use App\Domain\Events\RecordDomainEvent;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubmitOrder
{
    public function __construct(private RecordDomainEvent $events) {}

    /** @param array<string, mixed> $input */
    public function handle(array $input, ?int $cashierId = null, ?string $deviceId = null): Order
    {
        $data = Validator::make($input, [
            'order_type' => 'required|in:dine_in,takeaway',
            'table_number' => 'required_if:order_type,dine_in|nullable|integer|min:1',
            'customer_name' => 'required|string|max:100',
            'customer_phone' => 'nullable|string|max:20',
            'cart' => 'required|array|min:1',
            'cart.*.id' => 'required|integer|distinct',
            'cart.*.qty' => 'required|integer|min:1|max:100',
        ])->validate();

        $productIds = collect($data['cart'])->pluck('id');
        $products = Product::query()
            ->with(['discounts', 'recipes'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $lines = [];
        $total = 0;

        foreach ($data['cart'] as $item) {
            $product = $products->get($item['id']);

            if (! $product || ! $product->is_available) {
                throw ValidationException::withMessages(['cart' => 'A selected product is unavailable.']);
            }

            $price = (float) $product->active_price;
            $subtotal = round($price * $item['qty'], 2);
            $total = round($total + $subtotal, 2);
            $lines[] = [
                'product_id' => $product->id,
                'quantity' => $item['qty'],
                'unit_price' => $price,
                'subtotal' => $subtotal,
                'recipe_snapshot' => $product->recipes->map(fn ($recipe): array => [
                    'inventory_item_id' => $recipe->inventory_item_id,
                    'quantity_required' => (float) $recipe->quantity_required,
                ])->all(),
            ];
        }

        return DB::transaction(function () use ($data, $cashierId, $deviceId, $lines, $total): Order {
            $order = Order::create([
                'order_number' => 'POS-'.Str::upper(Str::random(12)),
                'user_id' => $cashierId,
                'device_id' => $deviceId,
                'table_number' => $data['order_type'] === 'dine_in' ? $data['table_number'] : null,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'order_type' => $data['order_type'],
                'status' => 'awaiting_inventory',
                'total_amount' => $total,
                'payment_method' => 'cash',
                'expires_at' => $deviceId === null ? now()->addMinutes(15) : null,
            ]);

            $order->items()->createMany($lines);
            $this->events->record($order, 'OrderSubmitted', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_amount' => $order->total_amount,
                'item_count' => count($lines),
            ]);

            return $order;
        });
    }
}
