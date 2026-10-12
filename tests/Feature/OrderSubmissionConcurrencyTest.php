<?php

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

test('simultaneous MySQL submissions serialize their claim and converge safely', function (bool $conflicting) {
    if (DB::connection()->getDatabaseName() !== 'testing' || DB::selectOne('select database() as name')->name !== 'testing') {
        throw new RuntimeException('Submission concurrency tests require the testing database.');
    }
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $barrier = sys_get_temp_dir().'/pos-submission-'.Str::uuid();
    mkdir($barrier, 0700);
    $processes = [];
    try {
        $product = Product::factory()->withRecipe(5)->create();
        $key = (string) Str::uuid();
        $device = (string) Str::uuid();
        for ($index = 0; $index < 2; $index++) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/submit-order-process.php')], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'DB_URL' => '',
                'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
            ], json_encode([
                'input' => ['order_type' => 'takeaway', 'customer_name' => $conflicting ? 'Customer '.$index : 'Same customer',
                    'cart' => [['id' => $product->id, 'qty' => 1]]],
                'key' => $key, 'device' => $device, 'barrier' => $barrier, 'index' => $index,
            ], JSON_THROW_ON_ERROR), 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        while (! is_file($barrier.'/ready-0') || ! is_file($barrier.'/ready-1')) {
            if (microtime(true) > $deadline || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                throw new RuntimeException('Child failed before barrier: '.$processes[0]->getErrorOutput().$processes[1]->getErrorOutput());
            }
            usleep(10000);
        }
        file_put_contents($barrier.'/go', '1');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($results, 'result');
        sort($statuses);
        expect($statuses)->toBe($conflicting ? ['conflict', 'success'] : ['success', 'success'])
            ->and($results[0]['order_id'])->toBe($results[1]['order_id'])
            ->and(is_file($barrier.'/claimed-0'))->toBeTrue()->and(is_file($barrier.'/claimed-1'))->toBeTrue()
            ->and(Order::count())->toBe(1)->and(DB::table('order_items')->count())->toBe(1)
            ->and(DB::table('order_submissions')->count())->toBe(1)
            ->and(DB::table('order_submissions')->whereNull('order_id')->count())->toBe(0)
            ->and(DB::table('domain_outbox')->where('event_name', 'OrderSubmitted')->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($barrier.'/*') as $path) {
            unlink($path);
        }
        rmdir($barrier);
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
})->with(['matching requests' => false, 'different contents' => true]);
