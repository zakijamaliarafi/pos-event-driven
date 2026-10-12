<?php

use App\Domain\Catalog\ManageCatalog;
use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Orders\SubmitOrder;
use App\Models\Discount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function discountCommandTestInput(array $overrides = []): array
{
    return array_replace([
        'name' => 'Promo', 'discount_type' => 'percentage', 'discount_value' => '10',
        'schedule_type' => 'interval', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07',
        'routine_day' => null, 'start_time' => null, 'end_time' => null, 'is_active' => true,
    ], $overrides);
}

test('discount commands reject unusable schedules values and product selections without writes', function (array $overrides, string $selection, string $field) {
    $product = Product::factory()->create();
    $ids = match ($selection) {
        'empty' => [], 'duplicate' => [$product->id, $product->id], 'missing' => [999999],
        default => [$product->id],
    };
    if ($selection === 'deleted') {
        $product->delete();
    }
    try {
        app(ManageCatalog::class)->saveDiscount(null, discountCommandTestInput($overrides), $ids);
        test()->fail('Expected discount validation to fail');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(Discount::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
})->with([
    [['discount_value' => '0'], 'valid', 'discount_value'],
    [['discount_value' => '100.01'], 'valid', 'discount_value'],
    [['discount_value' => '0.001'], 'valid', 'discount_value'],
    [['discount_value' => '1e1'], 'valid', 'discount_value'],
    [['discount_type' => 'fixed_amount', 'discount_value' => '100000000'], 'valid', 'discount_value'],
    [['start_date' => null], 'valid', 'start_date'],
    [['end_date' => null], 'valid', 'end_date'],
    [['end_date' => '2026-09-30'], 'valid', 'end_date'],
    [['schedule_type' => 'routine'], 'valid', 'routine_day'],
    [['schedule_type' => 'routine', 'routine_day' => 0, 'start_time' => '12:00', 'end_time' => '12:00'], 'valid', 'end_time'],
    [['schedule_type' => 'routine', 'routine_day' => 0, 'start_time' => '22:00', 'end_time' => '01:00'], 'valid', 'end_time'],
    [[], 'empty', 'products'], [[], 'duplicate', 'products.0'], [[], 'missing', 'products.0'], [[], 'deleted', 'products.0'],
]);

test('discount value and schedule boundaries persist normalized fields with durable events', function (array $overrides) {
    $product = Product::factory()->withRecipe(10, 1)->create();
    $ingredient = $product->recipes->sole()->inventoryItem;
    $discount = app(ManageCatalog::class)->saveDiscount(null, discountCommandTestInput($overrides), [$product->id]);
    expect($discount->products->modelKeys())->toBe([$product->id])->and($discount->version)->toBe(1)
        ->and(DB::table('domain_outbox')->where('event_name', 'DiscountCreated')->count())->toBe(1)
        ->and($ingredient->fresh()->current_stock)->toBe('10.00')->and($ingredient->fresh()->reserved_stock)->toBe('0.00');
    if ($discount->schedule_type === 'routine') {
        expect($discount->start_date)->toBeNull()->and($discount->end_date)->toBeNull();
    } else {
        expect($discount->routine_day)->toBeNull()->and($discount->start_time)->toBeNull()->and($discount->end_time)->toBeNull();
    }
})->with([
    [['discount_value' => '0.01']], [['discount_value' => '100']],
    [['discount_type' => 'fixed_amount', 'discount_value' => '99999999.99']],
    [['end_date' => '2026-10-01']],
    [['schedule_type' => 'routine', 'routine_day' => 0, 'start_time' => '00:00', 'end_time' => '23:59']],
]);

test('discount product existence is rechecked after acquiring the aggregate lock', function () {
    $product = Product::factory()->create();
    $discount = app(ManageCatalog::class)->saveDiscount(null, discountCommandTestInput(), [$product->id]);
    $deleteAtLock = true;
    DB::listen(function (QueryExecuted $query) use (&$deleteAtLock, $product): void {
        if ($deleteAtLock && str_contains($query->sql, 'from `discounts`') && str_contains($query->sql, 'for update')) {
            $deleteAtLock = false;
            $product->delete();
        }
    });
    expect(fn () => app(ManageCatalog::class)->saveDiscount($discount->id, discountCommandTestInput(['name' => 'Changed']), [$product->id]))->toThrow(ValidationException::class);
    expect($discount->fresh()->name)->toBe('Promo')->and($discount->fresh()->version)->toBe(1)
        ->and(DB::table('domain_outbox')->count())->toBe(1);
});

test('activation rejects invalid legacy discounts without changing their version or outbox', function () {
    $discount = Discount::create(discountCommandTestInput(['is_active' => false]));
    expect(fn () => app(ManageCatalog::class)->toggleDiscount($discount->id))->toThrow(ValidationException::class);
    expect($discount->fresh()->is_active)->toBeFalse()->and($discount->fresh()->version)->toBe(0)
        ->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('discount edits roll back assignments and events and can be retried', function () {
    $products = Product::factory()->count(2)->create();
    $catalog = app(ManageCatalog::class);
    $discount = $catalog->saveDiscount(null, discountCommandTestInput(), [$products[0]->id]);
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw new RuntimeException('Discount outbox interrupted');
        }
    });
    expect(fn () => $catalog->saveDiscount($discount->id, discountCommandTestInput(['name' => 'Updated']), [$products[1]->id]))->toThrow(RuntimeException::class);
    expect($discount->fresh()->name)->toBe('Promo')->and($discount->fresh()->version)->toBe(1)
        ->and($discount->products()->pluck('products.id')->all())->toBe([$products[0]->id])
        ->and(DB::table('domain_outbox')->count())->toBe(1);
    $catalog->saveDiscount($discount->id, discountCommandTestInput(['name' => 'Updated']), [$products[1]->id]);
    expect($discount->fresh()->version)->toBe(2)->and(DB::table('domain_outbox')->where('event_name', 'DiscountUpdated')->count())->toBe(1);
});

test('pricing preserves best overlapping discount inclusive dates and zero clamping', function () {
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    $product = Product::factory()->create(['price' => '10000']);
    $catalog = app(ManageCatalog::class);
    $catalog->saveDiscount(null, discountCommandTestInput(['discount_value' => '20']), [$product->id]);
    $catalog->saveDiscount(null, discountCommandTestInput(['discount_type' => 'fixed_amount', 'discount_value' => '3000']), [$product->id]);
    expect($product->fresh()->active_price)->toBe(7000.0);
    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(23, 59));
    expect($product->fresh()->active_price)->toBe(7000.0);
    $this->travelTo(now()->setDate(2026, 10, 8)->setTime(0, 0));
    expect((float) $product->fresh()->active_price)->toBe(10000.0);
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    $catalog->saveDiscount(null, discountCommandTestInput(['discount_type' => 'fixed_amount', 'discount_value' => '15000']), [$product->id]);
    expect($product->fresh()->active_price)->toBe(0.0);
});

test('catalog commands version changes and emit durable events', function () {
    $catalog = app(ManageCatalog::class);
    $category = $catalog->saveCategory(null, 'General');
    $product = $catalog->saveProduct(null, ['name' => 'Item', 'category_id' => $category->id, 'price' => 12000, 'image_url' => null]);
    $ingredient = $catalog->saveIngredient(null, ['name' => 'Material', 'unit' => 'g', 'current_stock' => 20, 'low_stock_threshold' => 2]);
    $catalog->saveRecipe($product->id, $ingredient->id, 1.5);

    expect($product->fresh()->version)->toBe(2)
        ->and(Recipe::where('product_id', $product->id)->where('inventory_item_id', $ingredient->id)->count())->toBe(1)
        ->and(DB::table('domain_outbox')->where('event_name', 'RecipeUpdated')->count())->toBe(1)
        ->and(InventoryItem::find($ingredient->id)->current_stock)->toBe('20.00');
});

test('whole recipe replacement changes all components atomically with one normalized event', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $original = $product->recipes->first()->inventoryItem;
    $ingredients = InventoryItem::factory()->count(2)->create();
    app(ManageCatalog::class)->replaceRecipe($product->id, [
        ['inventory_item_id' => $ingredients[1]->id, 'quantity_required' => '0.01'],
        ['inventory_item_id' => $ingredients[0]->id, 'quantity_required' => '999999.99'],
    ], 0);

    $event = DB::table('domain_outbox')->sole();
    expect($product->fresh()->version)->toBe(1)
        ->and($product->recipes()->orderBy('inventory_item_id')->pluck('quantity_required')->all())->toBe(['999999.99', '0.01'])
        ->and($original->fresh()->current_stock)->toBe('10.00')
        ->and($original->fresh()->reserved_stock)->toBe('0.00')
        ->and($event->event_name)->toBe('RecipeReplaced')
        ->and($event->schema_version)->toBe(1)
        ->and(json_decode($event->payload, true))->toEqual([
            'product_id' => $product->id,
            'components' => [
                ['inventory_item_id' => $ingredients[0]->id, 'quantity_required' => '999999.99'],
                ['inventory_item_id' => $ingredients[1]->id, 'quantity_required' => '0.01'],
            ],
        ]);
    app(ProcessDomainEvent::class)->process($event->id);
    app(ProcessDomainEvent::class)->process($event->id);
    expect(DB::table('domain_event_receipts')->where('event_id', $event->id)->count())->toBe(4)
        ->and(DB::table('domain_outbox')->find($event->id)->processed_at)->not->toBeNull();
});

test('unchanged normalized recipes and empty recipes do not emit events', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $ingredient = $product->recipes->first()->inventoryItem;
    app(ManageCatalog::class)->replaceRecipe($product->id, [
        ['inventory_item_id' => $ingredient->id, 'quantity_required' => '2'],
    ], 0);
    $empty = Product::factory()->create();
    app(ManageCatalog::class)->replaceRecipe($empty->id, [], 0);
    expect(DB::table('domain_outbox')->count())->toBe(0)->and($product->fresh()->version)->toBe(0);
});

test('whole recipe commands reject stale edits before any changes', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $ingredient = $product->recipes->first()->inventoryItem;
    app(ManageCatalog::class)->saveRecipe($product->id, $ingredient->id, 3);
    expect(fn () => app(ManageCatalog::class)->replaceRecipe($product->id, [], 0))->toThrow(ValidationException::class);
    expect($product->recipes()->sole()->quantity_required)->toBe('3.00')
        ->and($product->fresh()->version)->toBe(1)->and(DB::table('domain_outbox')->count())->toBe(1);
});

test('whole recipe commands validate all ingredients and decimal quantities before writing', function (string $scenario) {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $ingredient = $product->recipes->first()->inventoryItem;
    $components = [['inventory_item_id' => $ingredient->id, 'quantity_required' => '3']];
    if ($scenario === 'deleted') {
        $ingredient->delete();
    } elseif ($scenario === 'missing') {
        $components[0]['inventory_item_id'] = 999999;
    } elseif ($scenario === 'duplicate') {
        $components[] = $components[0];
    } else {
        $components[0]['quantity_required'] = $scenario;
    }
    expect(fn () => app(ManageCatalog::class)->replaceRecipe($product->id, $components, 0))->toThrow(ValidationException::class);
    expect($product->recipes()->sole()->quantity_required)->toBe('2.00')
        ->and($product->fresh()->version)->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
})->with(['deleted', 'missing', 'duplicate', '0', '-1', '0.001', '1000000', '1e2']);

test('recipe and aggregate changes roll back when the durable outbox write fails', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $ingredient = $product->recipes->first()->inventoryItem;
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw new RuntimeException('Outbox write interrupted');
        }
    });
    expect(fn () => app(ManageCatalog::class)->replaceRecipe($product->id, [
        ['inventory_item_id' => $ingredient->id, 'quantity_required' => '4'],
    ], 0))->toThrow(RuntimeException::class, 'Outbox write interrupted');
    expect($product->recipes()->sole()->quantity_required)->toBe('2.00')
        ->and($product->fresh()->version)->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('recipe replacement preserves existing order snapshots and ingredient reservations', function () {
    $product = Product::factory()->withRecipe(10, 2)->create();
    $ingredient = $product->recipes->first()->inventoryItem;
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Recipe Customer',
        'cart' => [['id' => $product->id, 'qty' => 2]],
    ], idempotencyKey: (string) Str::uuid());
    app(ProcessDomainEvent::class)->process(DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->value('id'));
    $snapshot = $order->items()->sole()->recipe_snapshot;
    $reserved = $ingredient->fresh()->reserved_stock;
    app(ManageCatalog::class)->replaceRecipe($product->id, [], 0);
    expect($order->items()->sole()->recipe_snapshot)->toBe($snapshot)
        ->and($ingredient->fresh()->reserved_stock)->toBe($reserved)
        ->and($ingredient->fresh()->current_stock)->toBe('10.00');
});
