<?php

use App\Domain\Orders\SubmitOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function dashboardManager(): User
{
    Role::findOrCreate('manager', 'web');
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    return $manager;
}

function dashboardSale(Product $product, ?string $paidAt, int $quantity = 1): Order
{
    $order = app(SubmitOrder::class)->handle([
        'order_type' => 'takeaway',
        'customer_name' => 'Dashboard customer',
        'cart' => [['id' => $product->id, 'qty' => $quantity]],
    ], idempotencyKey: (string) Str::uuid());

    DB::table('order_read_models')->insert([
        'order_id' => $order->id,
        'order_number' => $order->order_number,
        'status' => $paidAt === null ? 'ready' : 'completed',
        'customer_name' => $order->customer_name,
        'total_amount' => $order->total_amount,
        'paid_at' => $paidAt,
        'version' => $order->version,
        'created_at' => $order->created_at,
        'updated_at' => now(),
    ]);

    return $order;
}

test('guests are redirected from the dashboard to login', function () {
    $this->get(route('dashboard.revenue'))->assertRedirect(route('login'));
});

test('manager can open the sales dashboard', function () {
    Role::findOrCreate('manager', 'web');
    $user = User::factory()->create();
    $user->assignRole('manager');

    $this->actingAs($user)->get(route('dashboard.revenue'))->assertOk();
});

test('other staff and users without a role cannot open the manager dashboard', function (?string $role) {
    $user = User::factory()->create();

    if ($role !== null) {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    }

    $this->actingAs($user)->get(route('dashboard.revenue'))->assertForbidden();
})->with(['cashier', 'kitchen', 'waiter', null]);

test('dashboard renders the reference sections and an empty zero-filled chart', function () {
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(14, 30));

    Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')
        ->assertSee('Dasbor penjualan')
        ->assertSee('Pendapatan tunai')
        ->assertSee('Pesanan dibayar')
        ->assertSee('Tren pesanan')
        ->assertSee('Produk terlaris')
        ->assertSee('Belum ada penjualan yang dibayar pada periode ini.')
        ->assertSee('Hari ini')->assertSee('Minggu ini')->assertSee('Bulan ini')
        ->assertDontSee('Siap disajikan')->assertDontSee('wire:poll', false)
        ->assertViewHas('revenue', 0.0)
        ->assertViewHas('orderCount', 0)
        ->assertViewHas('chartMax', 1)
        ->assertViewHas('trend', function (array $trend): bool {
            expect($trend)->toHaveCount(24)
                ->and(array_column($trend, 'count'))->toBe(array_fill(0, 24, 0))
                ->and($trend[0]['label'])->toBe('00:00')
                ->and($trend[23]['label'])->toBe('23:00');

            return true;
        });

});

test('dashboard filters all sections by payment time and fills hourly or daily buckets', function (string $range, int $count, int $buckets, string $firstLabel) {
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(14, 30));
    $product = Product::factory()->withRecipe()->create(['name' => 'Tahu', 'price' => 12500]);

    foreach (['2026-09-30 23:59:59', '2026-10-01 00:00:00', '2026-10-11 23:59:59', '2026-10-12 00:00:00', '2026-10-13 12:00:00', '2026-10-14 00:00:00', '2026-10-14 14:01:00', '2026-10-14 14:30:00', '2026-10-14 14:30:01', null] as $paidAt) {
        dashboardSale($product, $paidAt);
    }

    Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')
        ->set('range', $range)
        ->assertViewHas('orderCount', $count)
        ->assertViewHas('revenue', (float) ($count * 12500))
        ->assertViewHas('topProducts', function ($products) use ($count): bool {
            expect($products)->toHaveCount(1)
                ->and((int) $products->first()->units)->toBe($count)
                ->and((float) $products->first()->revenue)->toBe((float) ($count * 12500));

            return true;
        })
        ->assertViewHas('trend', function (array $trend) use ($range, $count, $buckets, $firstLabel): bool {
            expect($trend)->toHaveCount($buckets)
                ->and(array_sum(array_column($trend, 'count')))->toBe($count)
                ->and($trend[0]['label'])->toBe($firstLabel);

            if ($range === 'today') {
                expect($trend[0]['count'])->toBe(1)
                    ->and($trend[1]['count'])->toBe(0)
                    ->and($trend[14]['count'])->toBe(2);
            } elseif ($range === 'month') {
                expect($trend[1]['count'])->toBe(0);
            }

            return true;
        });
})->with([
    ['today', 3, 24, '00:00'],
    ['week', 5, 3, '12/10'],
    ['month', 7, 14, '01/10'],
]);

test('top products rank by paid units and use historical subtotals after price changes', function () {
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(14, 30));

    foreach (range(1, 6) as $quantity) {
        $product = Product::factory()->withRecipe()->create(['name' => 'Produk '.$quantity, 'price' => 10000]);
        dashboardSale($product, '2026-10-14 12:00:00', $quantity);
        $product->update(['price' => 90000]);
    }

    $unpaid = Product::factory()->withRecipe()->create(['name' => 'Belum dibayar', 'price' => 10000]);
    dashboardSale($unpaid, null, 100);

    Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')
        ->assertDontSee('Belum dibayar')
        ->assertViewHas('revenue', 210000.0)
        ->assertViewHas('topProducts', function ($products): bool {
            expect($products)->toHaveCount(5)
                ->and($products->pluck('name')->all())->toBe(['Produk 6', 'Produk 5', 'Produk 4', 'Produk 3', 'Produk 2'])
                ->and((float) $products->first()->revenue)->toBe(60000.0);

            return true;
        });

});

test('period changes and realtime events refresh every dashboard section', function () {
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(14, 30));
    $product = Product::factory()->withRecipe()->create(['name' => 'Tahu realtime', 'price' => 15000]);
    dashboardSale($product, '2026-10-13 12:00:00');

    $dashboard = Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')
        ->assertViewHas('orderCount', 0)
        ->assertDontSee('Tahu realtime')
        ->set('range', 'week')
        ->assertSee('Tahu realtime')
        ->assertViewHas('orderCount', 1)
        ->assertViewHas('revenue', 15000.0);

    dashboardSale($product, '2026-10-14 14:00:00', 2);

    $dashboard->dispatch('order-view-changed')
        ->assertViewHas('orderCount', 2)
        ->assertViewHas('revenue', 45000.0)
        ->assertViewHas('trend', fn (array $trend): bool => array_column($trend, 'count') === [0, 1, 1])
        ->assertViewHas('topProducts', fn ($products): bool => (int) $products->first()->units === 3 && (float) $products->first()->revenue === 45000.0);
});

test('a single daily bucket has finite chart coordinates and a visible point', function (string $range) {
    $this->travelTo(now()->setDate(2026, 6, 1)->setTime(14, 30));
    $product = Product::factory()->withRecipe()->create(['price' => 10000]);
    dashboardSale($product, '2026-06-01 12:00:00');

    Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')
        ->set('range', $range)
        ->assertViewHas('chartMax', 1)
        ->assertViewHas('trend', fn (array $trend): bool => count($trend) === 1 && $trend[0]['count'] === 1)
        ->assertViewHas('trendCharts', function (array $charts): bool {
            foreach ($charts as $chart) {
                expect($chart['points'])->toHaveCount(1)
                    ->and($chart['points'][0]['x'])->toBe(($chart['left'] + $chart['right']) / 2)
                    ->and($chart['points'][0]['y'])->toEqual(16)
                    ->and($chart['labels'])->toHaveCount(1);
            }

            return true;
        })
        ->assertDontSee('NaN')->assertDontSee('INF');
})->with(['week', 'month']);

test('dashboard retains reporting boundaries without displaying the reporting information section', function (string $range, string $start) {
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(14, 30));

    Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')
        ->set('range', $range)
        ->assertViewHas('periodStart', $start)
        ->assertViewHas('periodCutoff', '14 Oktober 2026, 14:30')
        ->assertViewHas('periodTimezone', config('app.timezone'))
        ->assertDontSee('data-test="reporting-period"', false)
        ->assertDontSee('Zona waktu:')
        ->assertDontSee('Penjualan dihitung berdasarkan waktu pembayaran.')
        ->assertSeeInOrder(['Tren pesanan', 'Pendapatan tunai', 'Pesanan dibayar', 'Produk terlaris']);
})->with([
    ['today', '14 Oktober 2026, 00:00'],
    ['week', '12 Oktober 2026, 00:00'],
    ['month', '1 Oktober 2026, 00:00'],
]);

test('programmatic refresh retains the selected period and updates all reporting sections', function () {
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(14, 30));
    $product = Product::factory()->withRecipe()->create(['price' => 12500]);
    dashboardSale($product, '2026-10-13 12:00:00');

    $dashboard = Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')
        ->set('range', 'week')
        ->assertViewHas('orderCount', 1);

    dashboardSale($product, '2026-10-14 14:00:00', 2);
    $this->travelTo(now()->setTime(14, 35));

    $dashboard->call('refreshMetrics')
        ->assertSet('range', 'week')
        ->assertViewHas('periodStart', '12 Oktober 2026, 00:00')
        ->assertViewHas('periodCutoff', '14 Oktober 2026, 14:35')
        ->assertViewHas('orderCount', 2)
        ->assertViewHas('revenue', 37500.0)
        ->assertViewHas('trend', fn (array $trend): bool => array_column($trend, 'count') === [0, 1, 1])
        ->assertViewHas('topProducts', fn ($products): bool => (int) $products->first()->units === 3 && (float) $products->first()->revenue === 37500.0);
});

test('fitted charts preserve every bucket with bounded labels and a matching data disclosure', function (string $range) {
    $this->travelTo(now()->setDate(2026, 10, 31)->setTime(14, 30));
    $product = Product::factory()->withRecipe()->create();
    dashboardSale($product, '2026-10-31 12:00:00');

    $dashboard = Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')->set('range', $range);
    $trend = $dashboard->viewData('trend');

    foreach ($dashboard->viewData('trendCharts') as $variant => $chart) {
        expect(array_column($chart['points'], 'key'))->toBe(array_column($trend, 'key'))
            ->and(array_column($chart['points'], 'count'))->toBe(array_column($trend, 'count'))
            ->and(count($chart['labels']))->toBeLessThanOrEqual($variant === 'mobile' ? 6 : 8)
            ->and($chart['labels'][0]['key'])->toBe($trend[0]['key'])
            ->and($chart['labels'][array_key_last($chart['labels'])]['key'])->toBe($trend[array_key_last($trend)]['key']);

        foreach ($chart['points'] as $point) {
            expect(is_finite((float) $point['x']))->toBeTrue()
                ->and(is_finite((float) $point['y']))->toBeTrue()
                ->and($point['x'])->toBeGreaterThanOrEqual($chart['left'])->toBeLessThanOrEqual($chart['right'])
                ->and($point['y'])->toBeGreaterThanOrEqual(16)->toBeLessThanOrEqual($chart['bottom']);
        }
    }

    $document = new DOMDocument;
    @$document->loadHTML($dashboard->html());
    $xpath = new DOMXPath($document);
    $disclosure = $xpath->query('//details[@data-test="trend-data"]')->item(0);
    expect($disclosure->hasAttribute('open'))->toBeFalse()
        ->and($disclosure->hasAttribute('wire:ignore.self'))->toBeTrue();

    $rows = $xpath->query('//table[@data-test="trend-data-table"]/tbody/tr');
    expect($rows->length)->toBe(count($trend));

    foreach ($rows as $index => $row) {
        expect(trim($xpath->query('./th', $row)->item(0)->textContent))->toBe($trend[$index]['label'])
            ->and(trim($xpath->query('./td', $row)->item(0)->textContent))->toBe(number_format($trend[$index]['count']));
    }

    $titles = $xpath->query('//*[@id="order-trend-title-mobile" or @id="order-trend-title-desktop"]');
    expect($titles->length)->toBe(2);
    $dashboard->assertSee('Lihat data tren')->assertDontSee('min-w-[900px]', false)->assertDontSee('min-w-[600px]', false);
})->with(['today', 'week', 'month']);

test('empty dashboard has clear messages and finite chart coordinates', function () {
    $dashboard = Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')
        ->assertSee('Belum ada pesanan dibayar pada periode ini. Grafik menampilkan nilai nol.')
        ->assertSee('Belum ada penjualan yang dibayar pada periode ini.')
        ->assertDontSee('wire:click="refreshMetrics"', false)
        ->assertSee('Memuat dasbor…');

    foreach ($dashboard->viewData('trendCharts') as $chart) {
        foreach ($chart['points'] as $point) {
            expect(is_finite((float) $point['x']))->toBeTrue()
                ->and($point['y'])->toEqual($chart['bottom']);
        }
    }
});

test('ranked product layouts retain long names and large historical totals', function () {
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(14, 30));
    $name = str_repeat('Tahu istimewa ', 18);
    $product = Product::factory()->withRecipe()->create(['name' => $name, 'price' => 49999999.99]);
    dashboardSale($product, '2026-10-14 12:00:00', 2);
    $product->update(['price' => 10000]);

    Livewire::actingAs(dashboardManager())->test('pages::dashboard.revenue')
        ->assertSee($name)
        ->assertSee('100,000,000')
        ->assertSee('Nama produk')
        ->assertSee('Unit terjual')
        ->assertSee('Pendapatan')
        ->assertSee('Peringkat 1')
        ->assertSee('data-test="top-products-cards"', false)
        ->assertSee('data-test="top-products-table"', false)
        ->assertViewHas('revenue', 99999999.98)
        ->assertViewHas('topProducts', fn ($products): bool => (int) $products->first()->units === 2 && (float) $products->first()->revenue === 99999999.98);
});
