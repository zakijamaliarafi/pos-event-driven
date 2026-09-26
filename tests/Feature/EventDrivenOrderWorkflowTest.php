<?php

use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\TransitionOrder;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function drainDomainEvents(): void
{
    for ($pass = 0; $pass < 20; $pass++) {
        $ids = DB::table('domain_outbox')->whereNull('processed_at')->orderBy('sequence')->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        foreach ($ids as $id) {
            app(ProcessDomainEvent::class)->process($id);
        }
    }

    throw new RuntimeException('The domain event queue did not drain.');
}

test('cash order reserves product and recipe stock then consumes it once on preparation', function () {
    $product = Product::factory()->withStock(5)->create(['price' => 25000]);
    $ingredient = InventoryItem::create(['name' => 'Base', 'unit' => 'g', 'current_stock' => 10, 'low_stock_threshold' => 2]);
    Recipe::create(['product_id' => $product->id, 'inventory_item_id' => $ingredient->id, 'quantity_required' => 1.5]);
    $cashier = User::factory()->create();

    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'dine_in',
        'table_number' => 3,
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 2]],
    ]);

    expect($order->status)->toBe('awaiting_inventory');
    drainDomainEvents();
    expect($order->fresh()->status)->toBe('waiting_payment')
        ->and($product->fresh()->reserved_stock)->toBe(2)
        ->and($ingredient->fresh()->reserved_stock)->toBe('3.00');

    app(RecordCashPayment::class)->handle($order->id, $cashier->id, (string) Str::uuid());
    drainDomainEvents();
    expect($order->fresh()->status)->toBe('pending');

    app(TransitionOrder::class)->startPreparation($order->id);
    drainDomainEvents();
    expect($product->fresh()->current_stock)->toBe(3)
        ->and($product->fresh()->reserved_stock)->toBe(0)
        ->and($ingredient->fresh()->current_stock)->toBe('7.00')
        ->and($ingredient->fresh()->reserved_stock)->toBe('0.00');

    app(TransitionOrder::class)->markReady($order->id);
    app(TransitionOrder::class)->complete($order->id);
    drainDomainEvents();
    expect($order->fresh()->status)->toBe('completed')
        ->and(DB::table('order_read_models')->where('order_id', $order->id)->value('status'))->toBe('completed');
});

test('duplicate delivery does not reserve stock twice', function () {
    $product = Product::factory()->withStock(2)->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ]);
    $eventId = DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->value('id');

    app(ProcessDomainEvent::class)->process($eventId);
    app(ProcessDomainEvent::class)->process($eventId);

    expect($product->fresh()->reserved_stock)->toBe(1)
        ->and(DB::table('stock_reservations')->where('order_id', $order->id)->count())->toBe(1);
});

test('unpaid orders expire and release product stock', function () {
    $product = Product::factory()->withStock(1)->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ]);
    drainDomainEvents();
    $order->update(['expires_at' => now()->subMinute()]);

    app(TransitionOrder::class)->expire($order->id);
    drainDomainEvents();

    expect($order->fresh()->status)->toBe('expired')
        ->and($product->fresh()->current_stock)->toBe(1)
        ->and($product->fresh()->reserved_stock)->toBe(0);
});

test('competing orders cannot reserve the same final product unit', function () {
    $product = Product::factory()->withStock(1)->create();
    $input = [
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ];

    $first = app(SubmitOrder::class)->handle($input);
    $second = app(SubmitOrder::class)->handle($input);
    drainDomainEvents();

    $statuses = [$first->fresh()->status, $second->fresh()->status];
    sort($statuses);

    expect($statuses)->toBe(['rejected', 'waiting_payment'])
        ->and($product->fresh()->reserved_stock)->toBe(1);
});

test('cash payment retry records one payment and invalid transition is rejected', function () {
    $product = Product::factory()->withStock(2)->create();
    $cashier = User::factory()->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ]);
    drainDomainEvents();

    app(RecordCashPayment::class)->handle($order->id, $cashier->id, 'same-payment');
    app(RecordCashPayment::class)->handle($order->id, $cashier->id, 'same-payment');
    drainDomainEvents();

    expect(DB::table('cash_payments')->where('order_id', $order->id)->count())->toBe(1)
        ->and($order->fresh()->status)->toBe('pending');

    app(TransitionOrder::class)->markReady($order->id);
})->throws(ValidationException::class);

test('an outbox event remains pending while the dispatcher is not running', function () {
    $product = Product::factory()->withStock(2)->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ]);

    expect(DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->whereNull('processed_at')->count())->toBe(1)
        ->and($order->fresh()->status)->toBe('awaiting_inventory');

    drainDomainEvents();

    expect($order->fresh()->status)->toBe('waiting_payment');
});

test('reordered events wait for the preceding aggregate version', function () {
    $product = Product::factory()->withStock(1)->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ]);
    app(TransitionOrder::class)->cancel($order->id);

    $submittedId = DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->value('id');
    $cancelledId = DB::table('domain_outbox')->where('event_name', 'OrderCancelled')->value('id');

    expect(fn () => app(ProcessDomainEvent::class)->process($cancelledId))
        ->toThrow(RuntimeException::class);

    app(ProcessDomainEvent::class)->process($submittedId);
    app(ProcessDomainEvent::class)->process($cancelledId);
    drainDomainEvents();

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($product->fresh()->reserved_stock)->toBe(0)
        ->and(DB::table('domain_event_receipts')->where('event_id', $cancelledId)->count())->toBe(4);
});

test('recipe changes after submission do not change reserved ingredient quantities', function () {
    $product = Product::factory()->withStock(2)->create();
    $ingredient = InventoryItem::create(['name' => 'Base', 'unit' => 'g', 'current_stock' => 10, 'low_stock_threshold' => 2]);
    $recipe = Recipe::create(['product_id' => $product->id, 'inventory_item_id' => $ingredient->id, 'quantity_required' => 1.5]);
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 2]],
    ]);

    $recipe->update(['quantity_required' => 4]);
    drainDomainEvents();

    expect($ingredient->fresh()->reserved_stock)->toBe('3.00')
        ->and((float) DB::table('stock_reservations')->where('order_id', $order->id)->where('resource_type', 'ingredient')->value('quantity'))->toBe(3.0);
});

test('cancelling an unpaid reserved order releases its stock once', function () {
    $product = Product::factory()->withStock(1)->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ]);
    drainDomainEvents();

    app(TransitionOrder::class)->cancel($order->id);
    drainDomainEvents();
    drainDomainEvents();

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($product->fresh()->current_stock)->toBe(1)
        ->and($product->fresh()->reserved_stock)->toBe(0);
});

test('paid and ready orders notify the kitchen and waiter once', function () {
    Role::findOrCreate('kitchen', 'web');
    Role::findOrCreate('waiter', 'web');
    $cook = User::factory()->create();
    $cook->assignRole('kitchen');
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $cashier = User::factory()->create();
    $product = Product::factory()->withStock(1)->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ]);
    drainDomainEvents();
    app(RecordCashPayment::class)->handle($order->id, $cashier->id, 'cash-one');
    drainDomainEvents();

    expect($cook->notifications()->count())->toBe(1)
        ->and($waiter->notifications()->count())->toBe(0);

    app(TransitionOrder::class)->startPreparation($order->id);
    drainDomainEvents();
    app(TransitionOrder::class)->markReady($order->id);
    drainDomainEvents();
    drainDomainEvents();

    expect($cook->notifications()->count())->toBe(1)
        ->and($waiter->notifications()->count())->toBe(1)
        ->and($waiter->notifications()->first()->data['title'])->toContain('POS order');
});
