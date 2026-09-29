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
                    throw ValidationException::withMessages(['payment' => __('orders.errors.already_paid')]);
                }

                return $order;
            }

            $cashierSale = $order->device_id === null && $order->status === 'waiting_payment' && ! $order->expires_at?->isPast();
            $servedCustomerOrder = $order->device_id !== null && $order->status === 'awaiting_payment';

            if (! $cashierSale && ! $servedCustomerOrder) {
                throw ValidationException::withMessages(['payment' => __('orders.errors.not_awaiting_payment')]);
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
