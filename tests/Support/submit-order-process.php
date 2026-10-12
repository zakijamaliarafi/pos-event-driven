<?php

// Independent process for the MySQL submission race test; never targets the app database.
use App\Domain\Orders\SubmissionConflict;
use App\Domain\Orders\SubmitOrder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'testing' || DB::selectOne('select database() as name')->name !== 'testing') {
    throw new RuntimeException('Submission race processes require the testing database.');
}
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::connection()->beforeExecuting(function (string $sql) use ($data) {
    if (! str_starts_with($sql, 'insert into `order_submissions`')) {
        return;
    }
    file_put_contents($data['barrier'].'/attempting-'.$data['index'], '1');
    $deadline = microtime(true) + 10;
    while (! is_file($data['barrier'].'/attempting-'.(1 - $data['index']))) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The competing submission never attempted its claim.');
        }
        usleep(10000);
    }
});
DB::listen(function (QueryExecuted $query) use ($data) {
    if (str_starts_with($query->sql, 'insert into `order_submissions`')) {
        file_put_contents($data['barrier'].'/claimed-'.$data['index'], '1');
        // Hold the winning row lock while the other process attempts the same claim.
        usleep(300000);
    }
});
file_put_contents($data['barrier'].'/ready-'.$data['index'], '1');
$deadline = microtime(true) + 10;
while (! is_file($data['barrier'].'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Submission barrier timed out.');
    }
    usleep(10000);
}
try {
    $order = $app->make(SubmitOrder::class)->handle($data['input'], $data['key'], deviceId: $data['device']);
    echo json_encode(['result' => 'success', 'order_id' => $order->id], JSON_THROW_ON_ERROR);
} catch (SubmissionConflict $exception) {
    echo json_encode(['result' => 'conflict', 'order_id' => $exception->orderId], JSON_THROW_ON_ERROR);
}
