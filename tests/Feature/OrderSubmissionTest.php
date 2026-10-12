<?php

use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmissionConflict;
use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function submissionInput(Product $product): array
{
    return [
        'order_type' => 'dine_in', 'table_number' => 4,
        'customer_name' => 'Pelanggan', 'customer_phone' => '',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ];
}

function drainSubmissionEvents(): void
{
    for ($pass = 0; $pass < 30; $pass++) {
        $events = DB::table('domain_outbox')->whereNull('processed_at')->orderBy('sequence')->pluck('id');
        if ($events->isEmpty()) {
            return;
        }
        foreach ($events as $id) {
            app(ProcessDomainEvent::class)->process($id);
        }
    }
    throw new RuntimeException('Submission events did not drain.');
}

test('identical submissions create one immutable order and reserve ingredients once', function () {
    $product = Product::factory()->withRecipe(1)->create(['price' => 15000]);
    $ingredient = $product->recipes->first()->inventoryItem;
    $input = submissionInput($product);
    $key = (string) Str::uuid();
    $device = (string) Str::uuid();
    $orders = app(SubmitOrder::class);
    $order = $orders->handle($input, $key, deviceId: $device);
    expect($orders->handle($input, $key, deviceId: $device)->id)->toBe($order->id);
    drainSubmissionEvents();
    expect($orders->handle($input, $key, deviceId: $device)->status)->toBe('awaiting_verification');

    expect(Order::count())->toBe(1)->and(OrderItem::count())->toBe(1)
        ->and(DB::table('order_submissions')->count())->toBe(1)
        ->and(DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->count())->toBe(1)
        ->and(DB::table('stock_reservations')->count())->toBe(1)
        ->and($ingredient->fresh()->reserved_stock)->toBe('1.00');
});

test('replay ignores mutable catalog and stock changes', function (string $change) {
    $product = Product::factory()->withRecipe(5)->create(['price' => 15000]);
    $key = (string) Str::uuid();
    $input = submissionInput($product);
    $order = app(SubmitOrder::class)->handle($input, $key);
    $snapshot = $order->items->first()->recipe_snapshot;
    match ($change) {
        'price' => $product->update(['price' => 99000]),
        'recipe' => $product->recipes()->delete(),
        'deleted' => $product->delete(),
        'stock' => $product->recipes->first()->inventoryItem->update(['current_stock' => 0]),
    };
    $replay = app(SubmitOrder::class)->handle($input, $key);
    expect($replay->id)->toBe($order->id)->and($replay->total_amount)->toBe('15000.00')
        ->and($replay->items->first()->recipe_snapshot)->toBe($snapshot)
        ->and(DB::table('domain_outbox')->count())->toBe(1);
})->with(['price', 'recipe', 'deleted', 'stock']);

test('replay returns the original order after workflow transitions', function (string $state) {
    $product = Product::factory()->withRecipe(10)->create();
    $key = (string) Str::uuid();
    $input = submissionInput($product);
    $device = in_array($state, ['rejected', 'completed'], true) ? (string) Str::uuid() : null;
    $order = app(SubmitOrder::class)->handle($input, $key, deviceId: $device);
    drainSubmissionEvents();
    $transitions = app(TransitionOrder::class);
    match ($state) {
        'cancelled' => $transitions->cancel($order->id),
        'rejected' => $transitions->reject($order->id),
        'expired' => (function () use ($order, $transitions) {
            $this->travel(16)->minutes();
            $transitions->expire($order->id);
        })->call($this),
        'completed' => (function () use ($order, $transitions) {
            $transitions->verify($order->id);
            drainSubmissionEvents();
            $transitions->startPreparation($order->id);
            drainSubmissionEvents();
            $transitions->markReady($order->id);
            $transitions->complete($order->id);
            drainSubmissionEvents();
            app(RecordCashPayment::class)->handle($order->id, User::factory()->create()->id, 'submission-served-payment');
        })(),
        'pending' => app(RecordCashPayment::class)->handle($order->id, User::factory()->create()->id, 'submission-payment'),
    };
    drainSubmissionEvents();
    $eventCount = DB::table('domain_outbox')->count();
    expect(app(SubmitOrder::class)->handle($input, $key, deviceId: $device)->status)->toBe($state)
        ->and(DB::table('domain_outbox')->count())->toBe($eventCount)->and(Order::count())->toBe(1);
})->with(['cancelled', 'rejected', 'expired', 'pending', 'completed']);

test('normalization matches reordered carts integer strings and empty optional phone', function () {
    $products = Product::factory()->count(2)->withRecipe(10)->create();
    $input = submissionInput($products[0]);
    $input['cart'][] = ['id' => $products[1]->id, 'qty' => 2];
    $key = (string) Str::uuid();
    $order = app(SubmitOrder::class)->handle($input, $key);
    $input['table_number'] = '4';
    unset($input['customer_phone']);
    $input['cart'] = array_reverse(array_map(fn ($line) => ['id' => (string) $line['id'], 'qty' => (string) $line['qty']], $input['cart']));
    expect(app(SubmitOrder::class)->handle($input, strtoupper($key))->id)->toBe($order->id)
        ->and($order->customer_phone)->toBeNull();
});

test('takeaway table draft is ignored in both persistence and fingerprint', function () {
    $input = submissionInput(Product::factory()->withRecipe()->create());
    $input['order_type'] = 'takeaway';
    $input['table_number'] = 'invalid retained draft';
    $key = (string) Str::uuid();
    $order = app(SubmitOrder::class)->handle($input, $key);
    $input['table_number'] = 999;
    $input['customer_phone'] = null;
    expect(app(SubmitOrder::class)->handle($input, $key)->id)->toBe($order->id)
        ->and($order->table_number)->toBeNull();
});

test('changed submission content conflicts without overwriting the original claim', function (string $field) {
    $product = Product::factory()->withRecipe(10)->create();
    $input = submissionInput($product);
    $key = (string) Str::uuid();
    $order = app(SubmitOrder::class)->handle($input, $key);
    $claim = (array) DB::table('order_submissions')->first();
    match ($field) {
        'customer_name' => $input['customer_name'] = 'Berbeda',
        'customer_phone' => $input['customer_phone'] = '0812345',
        'table_number' => $input['table_number'] = 8,
        'order_type' => $input['order_type'] = 'takeaway',
        'quantity' => $input['cart'][0]['qty'] = 2,
        'product' => $input['cart'][0]['id'] = Product::factory()->withRecipe()->create()->id,
    };
    try {
        app(SubmitOrder::class)->handle($input, $key);
        $this->fail('Expected a submission conflict.');
    } catch (SubmissionConflict $exception) {
        expect($exception->orderId)->toBe($order->id);
    }
    expect((array) DB::table('order_submissions')->first())->toBe($claim)
        ->and(Order::count())->toBe(1)->and(OrderItem::count())->toBe(1)
        ->and(DB::table('domain_outbox')->count())->toBe(1);
})->with(['customer_name', 'customer_phone', 'table_number', 'order_type', 'quantity', 'product']);

test('a different key allows an intentional identical order', function () {
    $input = submissionInput(Product::factory()->withRecipe(5)->create());
    $first = app(SubmitOrder::class)->handle($input, (string) Str::uuid());
    $second = app(SubmitOrder::class)->handle($input, (string) Str::uuid());
    expect($first->id)->not->toBe($second->id)->and(Order::count())->toBe(2);
});

test('submission ownership is checked before returning orders or conflict details', function (string $actor) {
    $input = submissionInput(Product::factory()->withRecipe()->create());
    $key = (string) Str::uuid();
    $owner = $actor === 'device' ? ['deviceId' => (string) Str::uuid()] : ['cashierId' => User::factory()->create()->id];
    $other = $actor === 'device' ? ['deviceId' => (string) Str::uuid()] : ['cashierId' => User::factory()->create()->id];
    app(SubmitOrder::class)->handle($input, $key, ...$owner);
    $input['customer_name'] = 'Different request';
    foreach (['submit', 'lookup'] as $operation) {
        try {
            $operation === 'submit' ? app(SubmitOrder::class)->handle($input, $key, ...$other) : app(SubmitOrder::class)->findSubmission($key, ...$other);
            $this->fail('Expected ownership validation.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['cart'])->toBe([__('orders.errors.submission_unavailable')]);
        }
    }
    expect(Order::count())->toBe(1);
})->with(['device', 'cashier']);

test('invalid keys and invalid first submissions leave no claim', function (string $failure) {
    $product = Product::factory()->withRecipe(1)->create();
    $input = submissionInput($product);
    $key = (string) Str::uuid();
    match ($failure) {
        'key' => $key = '',
        'quantity' => $input['cart'][0]['qty'] = 1.5,
        'name' => $input['customer_name'] = '',
        'recipe' => $product->recipes()->delete(),
        'availability' => $input['cart'][0]['qty'] = 2,
    };
    expect(fn () => app(SubmitOrder::class)->handle($input, $key))->toThrow(ValidationException::class);
    expect(DB::table('order_submissions')->count())->toBe(0)->and(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
})->with(['key', 'quantity', 'name', 'recipe', 'availability']);

test('failure after outbox insert rolls back the entire submission and allows retry', function () {
    $input = submissionInput(Product::factory()->withRecipe()->create());
    $key = (string) Str::uuid();
    $fail = true;
    DB::listen(function (QueryExecuted $query) use (&$fail) {
        if ($fail && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $fail = false;
            throw new RuntimeException('Injected failure after outbox insert');
        }
    });
    expect(fn () => app(SubmitOrder::class)->handle($input, $key))->toThrow(RuntimeException::class);
    expect(Order::count())->toBe(0)->and(OrderItem::count())->toBe(0)
        ->and(DB::table('domain_outbox')->count())->toBe(0)->and(DB::table('order_submissions')->count())->toBe(0);
    app(SubmitOrder::class)->handle($input, $key);
    expect(Order::count())->toBe(1)->and(DB::table('order_submissions')->whereNull('order_id')->count())->toBe(0);
});

test('submission ledger prevents deletion of its original order', function () {
    $order = app(SubmitOrder::class)->handle(submissionInput(Product::factory()->withRecipe()->create()), (string) Str::uuid());
    expect(fn () => $order->delete())->toThrow(QueryException::class);
});
