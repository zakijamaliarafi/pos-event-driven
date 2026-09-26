<?php

use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\TransitionOrder;
use App\Jobs\DeliverDomainEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('Redis worker recovers the full order workflow after an outage without duplicate stock use', function () {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $queue = 'pos-recovery-'.Str::lower(Str::random(12));
    config(['pos.domain_queue' => $queue]);

    try {
        $product = Product::factory()->withStock(2)->create(['price' => 12000]);
        $cashier = User::factory()->create();
        $order = app(SubmitOrder::class)->handle([
            'order_type' => 'dine_in',
            'table_number' => 2,
            'customer_name' => 'Recovery customer',
            'cart' => [['id' => $product->id, 'qty' => 1]],
        ]);

        Artisan::call('domain:dispatch-outbox');
        expect($order->fresh()->status)->toBe('awaiting_inventory')
            ->and(DB::table('domain_outbox')->whereNull('processed_at')->count())->toBe(1);

        drainRecoveryQueue($queue);
        expect($order->fresh()->status)->toBe('waiting_payment')
            ->and($product->fresh()->reserved_stock)->toBe(1);

        app(RecordCashPayment::class)->handle($order->id, $cashier->id, 'recovery-cash');
        drainRecoveryQueue($queue);
        expect($order->fresh()->status)->toBe('pending');

        app(TransitionOrder::class)->startPreparation($order->id);
        drainRecoveryQueue($queue);
        app(TransitionOrder::class)->markReady($order->id);
        drainRecoveryQueue($queue);
        app(TransitionOrder::class)->complete($order->id);
        drainRecoveryQueue($queue);

        $submittedId = DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->value('id');
        DeliverDomainEvent::dispatch($submittedId)->onConnection('redis')->onQueue($queue);
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1]);

        expect(Order::findOrFail($order->id)->status)->toBe('completed')
            ->and($product->fresh()->current_stock)->toBe(1)
            ->and($product->fresh()->reserved_stock)->toBe(0)
            ->and(DB::table('order_read_models')->where('order_id', $order->id)->value('status'))->toBe('completed')
            ->and((float) DB::table('order_read_models')->where('order_id', $order->id)->value('total_amount'))->toBe(12000.0)
            ->and(DB::table('domain_outbox')->whereNull('processed_at')->count())->toBe(0);
    } finally {
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
});

function drainRecoveryQueue(string $queue): void
{
    for ($pass = 0; $pass < 30; $pass++) {
        Artisan::call('domain:dispatch-outbox');

        if (DB::table('domain_outbox')->whereNull('processed_at')->doesntExist()) {
            return;
        }

        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);
    }

    throw new RuntimeException('The Redis worker did not drain the domain outbox.');
}
