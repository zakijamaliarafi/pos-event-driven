<?php

namespace App\Domain\Orders;

use App\Domain\Events\DomainEvent;
use App\Events\OrderViewChanged;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $broadcast = new OrderViewChanged($order->id, $order->order_number, $order->status);
        $channels = ['private-staff.orders'];

        if ($order->device_id !== null) {
            $channels[] = 'private-customer.order.'.$order->id;
        }

        DB::table('order_broadcast_outbox')->insert([
            'id' => (string) Str::uuid(),
            'source_event_id' => $event->id,
            'order_id' => $order->id,
            'projection_version' => $order->version,
            'broadcast_connection' => config('broadcasting.default') ?? 'null',
            'channels' => json_encode($channels, JSON_THROW_ON_ERROR),
            'payload' => json_encode($broadcast->broadcastWith(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
