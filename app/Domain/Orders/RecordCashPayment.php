<?php

namespace App\Domain\Orders;

use App\Domain\Events\RecordDomainEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordCashPayment
{
    public function __construct(private RecordDomainEvent $events) {}

    public function handle(int $orderId, int $cashierId, string $idempotencyKey): Order
    {
        return DB::transaction(function () use ($orderId, $cashierId, $idempotencyKey): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $existing = DB::table('cash_payments')->where('order_id', $orderId)->first();

            if ($existing) {
                if ($existing->idempotency_key !== $idempotencyKey) {
                    throw ValidationException::withMessages(['payment' => 'This order has already been paid.']);
                }

                return $order;
            }

            if ($order->status !== 'waiting_payment' || $order->expires_at?->isPast()) {
                throw ValidationException::withMessages(['payment' => 'This order is not awaiting payment.']);
            }

            DB::table('cash_payments')->insert([
                'order_id' => $order->id,
                'cashier_id' => $cashierId,
                'amount' => $order->total_amount,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->events->record($order, 'CashPaymentRecorded', [
                'order_id' => $order->id,
                'amount' => $order->total_amount,
                'cashier_id' => $cashierId,
            ]);

            return $order;
        });
    }
}
