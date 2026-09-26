<?php

namespace App\Domain\Orders;

use App\Domain\Events\DomainEvent;
use App\Events\OrderViewChanged;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class OrderProjectionConsumer
{
    public function handle(DomainEvent $event): void
    {
        if ($event->aggregateType !== Order::class) {
            return;
        }

        $order = Order::find($event->aggregateId);

        if (! $order) {
            return;
        }

        $current = DB::table('order_read_models')->where('order_id', $order->id)->lockForUpdate()->first();

        if ($current && $current->version >= $order->version) {
            return;
        }

        DB::table('order_read_models')->updateOrInsert(['order_id' => $order->id], [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'customer_name' => $order->customer_name,
            'table_number' => $order->table_number,
            'total_amount' => $order->total_amount,
            'paid_at' => DB::table('cash_payments')->where('order_id', $order->id)->value('created_at'),
            'version' => $order->version,
            'created_at' => $order->created_at,
            'updated_at' => now(),
        ]);

        DB::afterCommit(fn () => OrderViewChanged::dispatch($order->id, $order->order_number, $order->status));
    }
}
