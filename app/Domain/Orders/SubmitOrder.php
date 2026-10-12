<?php

namespace App\Domain\Orders;

use App\Domain\Catalog\ProductAvailability;
use App\Domain\Events\RecordDomainEvent;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubmitOrder
{
    public function __construct(private RecordDomainEvent $events, private ProductAvailability $availability) {}

    /** @param array<string, mixed> $input */
    public function handle(array $input, string $idempotencyKey, ?int $cashierId = null, ?string $deviceId = null): Order
    {
        $this->validateKey($idempotencyKey);
        $idempotencyKey = strtolower($idempotencyKey);
        $data = $this->normalize($input);
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $idempotencyKey, $fingerprint, $cashierId, $deviceId): Order {
            // A no-op upsert serializes even the first two requests for a key.
            DB::table('order_submissions')->upsert([
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'cashier_id' => $cashierId,
                'device_id' => $deviceId,
                'order_id' => null,
                'created_at' => now(),
            ], ['idempotency_key'], ['idempotency_key']);
            $submission = DB::table('order_submissions')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            $this->assertOwner($submission, $cashierId, $deviceId);

            if ($submission->order_id !== null) {
                if (! hash_equals($submission->request_fingerprint, $fingerprint)) {
                    throw new SubmissionConflict((int) $submission->order_id);
                }

                return Order::findOrFail($submission->order_id);
            }

            $productIds = collect($data['cart'])->pluck('id');
            $products = Product::query()
                ->with(['discounts', 'recipes.inventoryItem'])
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            $lines = [];
            $total = 0;

            foreach ($data['cart'] as $item) {
                $product = $products->get($item['id']);

                if (! $product || ! $this->availability->hasValidRecipe($product)) {
                    throw ValidationException::withMessages(['cart' => __('orders.errors.product_unavailable')]);
                }

                if ($this->availability->availableQuantity($product) < $item['qty']) {
                    throw ValidationException::withMessages(['cart' => __('orders.errors.cart_changed')]);
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

            $order = Order::create([
                'order_number' => 'OTP-'.Str::upper(Str::random(12)),
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

            DB::table('order_submissions')->where('idempotency_key', $idempotencyKey)->update(['order_id' => $order->id]);

            return $order;
        }, attempts: 3);
    }

    public function findSubmission(string $idempotencyKey, ?int $cashierId = null, ?string $deviceId = null): ?Order
    {
        $this->validateKey($idempotencyKey);
        $submission = DB::table('order_submissions')->where('idempotency_key', strtolower($idempotencyKey))->first();

        if ($submission === null) {
            return null;
        }

        $this->assertOwner($submission, $cashierId, $deviceId);

        return $submission->order_id === null ? null : Order::findOrFail($submission->order_id);
    }

    private function validateKey(string $key): void
    {
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['cart' => __('orders.errors.submission_key_invalid')]);
        }
    }

    private function assertOwner(object $submission, ?int $cashierId, ?string $deviceId): void
    {
        $ownerCashier = $submission->cashier_id === null ? null : (int) $submission->cashier_id;
        if ($ownerCashier !== $cashierId || $submission->device_id !== $deviceId) {
            throw ValidationException::withMessages(['cart' => __('orders.errors.submission_unavailable')]);
        }
    }

    /** @return array<string, mixed> */
    private function normalize(array $input): array
    {
        if (($input['order_type'] ?? null) === 'takeaway') {
            $input['table_number'] = null;
        }
        $data = Validator::make($input, [
            'order_type' => 'required|in:dine_in,takeaway',
            'table_number' => 'required_if:order_type,dine_in|nullable|integer|min:1|max:2147483647',
            'customer_name' => 'required|string|max:100',
            'customer_phone' => 'nullable|string|max:20',
            'cart' => 'required|array|min:1',
            'cart.*.id' => 'required|integer|min:1|distinct',
            'cart.*.qty' => 'required|integer|min:1|max:100',
        ])->validate();
        $cart = array_map(fn (array $line): array => ['id' => (int) $line['id'], 'qty' => (int) $line['qty']], $data['cart']);
        usort($cart, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [
            'order_type' => $data['order_type'],
            'table_number' => $data['order_type'] === 'dine_in' ? (int) $data['table_number'] : null,
            'customer_name' => $data['customer_name'],
            'customer_phone' => ($data['customer_phone'] ?? '') === '' ? null : $data['customer_phone'],
            'cart' => $cart,
        ];
    }
}
