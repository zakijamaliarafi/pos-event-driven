<?php

use App\Domain\Catalog\ProductAvailability;
use App\Domain\Events\ProcessDomainEvent;
use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Domain\Orders\TransitionOrder;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function drainIngredientInventoryEvents(): void
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

    throw new RuntimeException('The ingredient inventory outbox did not drain.');
}

test('the removed inventory page has no route or manager navigation link', function () {
    Role::findOrCreate('manager', 'web');
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    expect(Route::has('menu.inventory'))->toBeFalse();
    $this->get('/menu/inventory')->assertNotFound();
    $this->actingAs($manager)->get('/menu/inventory')->assertNotFound();
    $this->get(route('menu.menu'))->assertOk()->assertDontSee('/menu/inventory');
    $this->get(route('menu.ingredient'))->assertOk();
    $this->get(route('menu.recipes'))->assertOk();
});

test('available portions use two decimal ingredient quantities and reservations', function (float $stock, float $reserved, float $required, int $expected) {
    $product = Product::factory()->withRecipe($stock, $required)->create([
        'current_stock' => 0, 'reserved_stock' => 99, 'is_available' => false,
    ]);
    $ingredient = $product->recipes()->firstOrFail()->inventoryItem;
    $ingredient->reserved_stock = $reserved;
    $ingredient->save();
    $product->load('recipes.inventoryItem');

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect(app(ProductAvailability::class)->availableQuantity($product))->toBe($expected)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with([
    'exact decimal boundary' => [0.29, 0, 0.01, 29],
    'fractional portions round down' => [0.28, 0, 0.1, 2],
    'reserved stock reduces portions' => [0.09, 0.03, 0.01, 6],
    'fully reserved' => [10, 10, 1.5, 0],
    'depleted' => [0, 0, 1, 0],
    'negative remainder is unavailable' => [1, 2, 1, 0],
]);

test('the limiting ingredient determines availability', function () {
    $product = Product::factory()->withRecipe(100, 2)->create();
    $ingredient = InventoryItem::factory()->create(['current_stock' => 7]);
    Recipe::create(['product_id' => $product->id, 'inventory_item_id' => $ingredient->id, 'quantity_required' => 3]);

    expect(app(ProductAvailability::class)->availableQuantity($product->load('recipes.inventoryItem')))->toBe(2);
});

test('invalid or missing recipes have no availability and cannot be submitted', function (string $scenario) {
    $product = Product::factory()->create();
    if ($scenario !== 'no recipe') {
        $ingredient = InventoryItem::factory()->create();
        Recipe::create([
            'product_id' => $product->id, 'inventory_item_id' => $ingredient->id,
            'quantity_required' => $scenario === 'zero requirement' ? 0 : 1,
        ]);
        if ($scenario === 'deleted ingredient') {
            $ingredient->delete();
        }
    }

    expect(app(ProductAvailability::class)->availableQuantity($product->load('recipes.inventoryItem')))->toBe(0);
    expect(fn () => app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 1]],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
})->with(['no recipe', 'deleted ingredient', 'zero requirement']);

test('missing products cannot be submitted', function () {
    expect(fn () => app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Customer',
        'cart' => [['id' => 99999, 'qty' => 1]],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('customer and cashier menus show ingredient portions and hide unavailable products', function (string $routeName) {
    if ($routeName === 'order.create') {
        Role::findOrCreate('cashier', 'web');
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $this->actingAs($cashier);
    }
    $product = Product::factory()->withRecipe(9, 3)->create([
        'name' => 'Ingredient available product', 'current_stock' => 0, 'is_available' => false,
    ]);
    $ingredient = $product->recipes()->firstOrFail()->inventoryItem;
    $ingredient->reserved_stock = 3;
    $ingredient->save();
    Product::factory()->create(['name' => 'No recipe product', 'current_stock' => 999, 'is_available' => true]);
    Product::factory()->withRecipe(0)->create(['name' => 'Depleted product', 'current_stock' => 999, 'is_available' => true]);

    $this->get(route($routeName))->assertOk()->assertSee('Ingredient available product')->assertSee('Tersedia: 2')
        ->assertDontSee('No recipe product')->assertDontSee('Depleted product')->assertDontSee('wire:poll', false);
})->with(['customer.order', 'order.create']);

test('new orders reserve and consume only ingredients regardless of legacy product fields', function () {
    $product = Product::factory()->withRecipe(0.29, 0.01)->create([
        'current_stock' => 5, 'reserved_stock' => 3, 'is_available' => false,
    ]);
    $ingredient = $product->recipes()->firstOrFail()->inventoryItem;
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Customer',
        'cart' => [['id' => $product->id, 'qty' => 29]],
    ], deviceId: (string) Str::uuid());
    drainIngredientInventoryEvents();

    expect($order->fresh()->status)->toBe('awaiting_verification')
        ->and($ingredient->fresh()->reserved_stock)->toBe('0.29')
        ->and(DB::table('stock_reservations')->where('resource_type', 'product')->count())->toBe(0);
    app(TransitionOrder::class)->verify($order->id);
    drainIngredientInventoryEvents();
    app(TransitionOrder::class)->startPreparation($order->id);
    drainIngredientInventoryEvents();

    expect($order->fresh()->status)->toBe('preparing')
        ->and($ingredient->fresh()->current_stock)->toBe('0.00')
        ->and($ingredient->fresh()->reserved_stock)->toBe('0.00')
        ->and($product->fresh()->current_stock)->toBe(5)
        ->and($product->fresh()->reserved_stock)->toBe(3)
        ->and($product->fresh()->is_available)->toBeFalse()
        ->and(DB::table('domain_outbox')->where('event_name', 'InventoryConsumed')->count())->toBe(1);
});

test('shared ingredient requirements are aggregated and rejection leaves no partial reservations', function () {
    $first = Product::factory()->withRecipe(10)->create();
    $second = Product::factory()->create();
    $shared = InventoryItem::factory()->create(['current_stock' => 3]);
    foreach ([$first, $second] as $product) {
        Recipe::create(['product_id' => $product->id, 'inventory_item_id' => $shared->id, 'quantity_required' => 2]);
    }
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Customer',
        'cart' => [['id' => $first->id, 'qty' => 1], ['id' => $second->id, 'qty' => 1]],
    ]);
    drainIngredientInventoryEvents();

    expect($order->fresh()->status)->toBe('rejected')
        ->and(DB::table('stock_reservations')->count())->toBe(0)
        ->and(InventoryItem::where('reserved_stock', '>', 0)->count())->toBe(0);
});

test('shared ingredient requirements create one reservation with the summed quantity', function () {
    $shared = InventoryItem::factory()->create(['current_stock' => 0.5]);
    $products = Product::factory()->count(2)->create();
    foreach ($products as $product) {
        Recipe::create(['product_id' => $product->id, 'inventory_item_id' => $shared->id, 'quantity_required' => 0.1]);
    }
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Customer',
        'cart' => $products->map(fn (Product $product): array => ['id' => $product->id, 'qty' => 2])->all(),
    ]);
    drainIngredientInventoryEvents();

    expect($order->fresh()->status)->toBe('waiting_payment')
        ->and($shared->fresh()->reserved_stock)->toBe('0.40')
        ->and(DB::table('stock_reservations')->where('order_id', $order->id)->count())->toBe(1);
});

test('stock changes after submission are checked when reserving ingredients', function (string $change) {
    $product = Product::factory()->withRecipe(1)->create();
    $ingredient = $product->recipes()->firstOrFail()->inventoryItem;
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Customer', 'cart' => [['id' => $product->id, 'qty' => 1]],
    ]);
    if ($change === 'delete') {
        $ingredient->delete();
    } else {
        $ingredient->current_stock = 0;
        $ingredient->save();
    }
    drainIngredientInventoryEvents();

    expect($order->fresh()->status)->toBe('rejected')->and(DB::table('stock_reservations')->count())->toBe(0);
})->with(['delete', 'deplete']);

test('awaiting inventory orders with empty or invalid legacy snapshots are rejected', function (bool $empty) {
    $product = Product::factory()->withRecipe()->create();
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Customer', 'cart' => [['id' => $product->id, 'qty' => 1]],
    ]);
    $item = $order->items()->firstOrFail();
    $snapshot = $item->recipe_snapshot;
    $snapshot[0]['quantity_required'] = 0;
    $item->update(['recipe_snapshot' => $empty ? [] : $snapshot]);
    drainIngredientInventoryEvents();

    expect($order->fresh()->status)->toBe('rejected')->and(DB::table('stock_reservations')->count())->toBe(0);
})->with([true, false]);

test('legacy product reservations without recipes still finish once', function (string $action, bool $withIngredients) {
    $product = Product::factory()->withRecipe()->create(['current_stock' => 2, 'reserved_stock' => 1]);
    $ingredient = $product->recipes()->firstOrFail()->inventoryItem;
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway', 'customer_name' => 'Legacy customer', 'cart' => [['id' => $product->id, 'qty' => 1]],
    ], deviceId: in_array($action, ['prepare', 'expire'], true) ? null : (string) Str::uuid());
    $order->items()->update(['recipe_snapshot' => '[]']);
    $product->recipes()->delete();
    $order->update(['status' => in_array($action, ['prepare', 'expire'], true) ? 'waiting_payment' : 'awaiting_verification']);
    DB::table('stock_reservations')->insert([
        'order_id' => $order->id, 'resource_type' => 'product', 'resource_id' => $product->id,
        'quantity' => 1, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($withIngredients) {
        $ingredient->reserved_stock = 1;
        $ingredient->save();
        DB::table('stock_reservations')->insert([
            'order_id' => $order->id, 'resource_type' => 'ingredient', 'resource_id' => $ingredient->id,
            'quantity' => 1, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    drainIngredientInventoryEvents();

    if ($action === 'prepare') {
        app(RecordCashPayment::class)->handle($order->id, User::factory()->create()->id, 'legacy-payment');
        drainIngredientInventoryEvents();
        app(TransitionOrder::class)->startPreparation($order->id);
    } elseif ($action === 'verify') {
        app(TransitionOrder::class)->verify($order->id);
    } elseif ($action === 'reject') {
        app(TransitionOrder::class)->reject($order->id);
    } elseif ($action === 'expire') {
        $order->update(['expires_at' => now()->subMinute()]);
        app(TransitionOrder::class)->expire($order->id);
    } else {
        app(TransitionOrder::class)->cancel($order->id);
    }
    drainIngredientInventoryEvents();
    drainIngredientInventoryEvents();
    $consumed = in_array($action, ['prepare', 'verify'], true);

    expect($product->fresh()->current_stock)->toBe($consumed ? 1 : 2)
        ->and($product->fresh()->reserved_stock)->toBe(0)
        ->and(DB::table('stock_reservations')->where('status', $consumed ? 'consumed' : 'released')->count())->toBe($withIngredients ? 2 : 1)
        ->and(DB::table('domain_outbox')->where('event_name', $consumed ? 'InventoryConsumed' : 'InventoryReleased')->count())->toBe(1);
    if ($action === 'verify') {
        expect($order->fresh()->status)->toBe('pending');
    }
    if ($withIngredients) {
        expect($ingredient->fresh()->current_stock)->toBe($consumed ? '99.00' : '100.00')
            ->and($ingredient->fresh()->reserved_stock)->toBe('0.00');
    }
})->with(['prepare', 'verify', 'reject', 'cancel', 'expire'])->with([true, false]);

test('demo catalog seeds availability entirely through ingredients and recipes', function () {
    $this->seed(DemoCatalogSeeder::class);
    $product = Product::with('recipes.inventoryItem')->sole();

    expect(app(ProductAvailability::class)->availableQuantity($product))->toBe(50)
        ->and($product->current_stock)->toBe(0)
        ->and(DB::table('domain_outbox')->where('event_name', 'ProductStockAdjusted')->count())->toBe(0);
});
