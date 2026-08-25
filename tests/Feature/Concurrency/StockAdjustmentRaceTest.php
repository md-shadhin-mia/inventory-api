<?php

use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Symfony\Component\Process\Process;

function raceScratchDir(): string
{
    $dir = sys_get_temp_dir().'/inventory-race-'.getmypid();

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function raceChildScript(string $dir, int $userId, int $warehouseId, int $productId, int $delta, string $label): string
{
    $basePath = base_path();

    $script = <<<PHP
    <?php

    require '{$basePath}/vendor/autoload.php';

    \$app = require '{$basePath}/bootstrap/app.php';
    \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    // Signal readiness, then block until the parent releases both children
    // at once so the two adjustments genuinely overlap.
    touch('{$dir}/ready-{$label}');

    \$deadline = microtime(true) + 10;
    while (! file_exists('{$dir}/go')) {
        if (microtime(true) > \$deadline) {
            // HARNESS: prefix (never FAIL:) so a broken barrier can never be
            // mistaken by the parent for a legitimate business rejection.
            echo 'HARNESS:timeout-waiting-for-go';
            exit(1);
        }
        usleep(500);
    }

    try {
        app(App\Services\Contracts\InventoryServiceInterface::class)
            ->adjustStock({$userId}, {$warehouseId}, {$productId}, {$delta}, 'race test {$label}');
        echo 'OK';
    } catch (Throwable \$e) {
        echo 'FAIL:'.get_class(\$e).':'.\$e->getMessage();
    }
    PHP;

    $path = $dir.'/child-'.$label.'.php';
    file_put_contents($path, $script);

    return $path;
}

function raceChildEnv(): array
{
    $db = config('database.connections.pgsql');

    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $db['host'],
        'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => $db['database'],
        'DB_USERNAME' => $db['username'],
        'DB_PASSWORD' => $db['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'REDIS_QUEUE_DRIVER' => 'sync',
        'SESSION_DRIVER' => 'array',
        'BROADCAST_CONNECTION' => 'null',
        'MAIL_MAILER' => 'array',
        'TELESCOPE_ENABLED' => 'false',
        'PULSE_ENABLED' => 'false',
        'NIGHTWATCH_ENABLED' => 'false',
    ];
}

afterEach(function () {
    $dir = raceScratchDir();

    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($dir);

    InventoryTransaction::query()->delete();
    Inventory::query()->delete();
    Product::query()->delete();
    Warehouse::query()->delete();
    User::query()->delete();
});

it('serializes two parallel -7 adjustments on stock 10: one succeeds, one is rejected, final stock is 3', function () {

    $manager = User::factory()->warehouseManager()->create();
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();

    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $dir = raceScratchDir();
    $env = raceChildEnv();

    $processes = [];
    foreach (['a', 'b'] as $label) {
        $script = raceChildScript($dir, $manager->id, $warehouse->id, $product->id, -7, $label);
        $process = new Process([PHP_BINARY, $script], base_path(), $env, null, 30);
        $process->start();
        $processes[$label] = $process;
    }

    $deadline = microtime(true) + 15;
    while (! file_exists($dir.'/ready-a') || ! file_exists($dir.'/ready-b')) {
        if (microtime(true) > $deadline) {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            $this->fail(
                'Race harness failure: children never reached the start line. '
                .'Output A: '.$processes['a']->getOutput().$processes['a']->getErrorOutput().' | '
                .'Output B: '.$processes['b']->getOutput().$processes['b']->getErrorOutput()
            );
        }
        usleep(1000);
    }

    touch($dir.'/go');

    foreach ($processes as $process) {
        $process->wait();
    }

    $outputs = [
        'a' => trim($processes['a']->getOutput()),
        'b' => trim($processes['b']->getOutput()),
    ];

    $diagnostics = ' Outputs: '.json_encode($outputs)
        .' Stderr: '.$processes['a']->getErrorOutput().$processes['b']->getErrorOutput()
        .' Exit codes: '.json_encode(['a' => $processes['a']->getExitCode(), 'b' => $processes['b']->getExitCode()]);

    $harnessFailures = array_filter($outputs, fn (string $out) => str_starts_with($out, 'HARNESS:'));

    expect($harnessFailures)->toHaveCount(0, 'The race harness itself failed; the result proves nothing about locking.'.$diagnostics);

    expect($processes['a']->getExitCode())->toBe(0, 'Child A did not exit cleanly.'.$diagnostics)
        ->and($processes['b']->getExitCode())->toBe(0, 'Child B did not exit cleanly.'.$diagnostics);

    $succeeded = array_filter($outputs, fn (string $out) => $out === 'OK');

    $rejected = array_filter(
        $outputs,
        fn (string $out) => str_starts_with($out, 'FAIL:'.InsufficientStockException::class.':'),
    );

    expect($succeeded)->toHaveCount(1, 'Exactly one of the two parallel adjustments must succeed.'.$diagnostics)
        ->and($rejected)->toHaveCount(1, 'Exactly one of the two parallel adjustments must be rejected with '.InsufficientStockException::class.'.'.$diagnostics);

    $finalQuantity = (int) Inventory::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('quantity');

    expect($finalQuantity)->toBe(3, 'Final committed balance must be 10 - 7 = 3.'.$diagnostics);

    $transactions = InventoryTransaction::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->get();

    expect($transactions)->toHaveCount(1, 'Exactly one audit row must exist; more than one means a lost update.'.$diagnostics)
        ->and((int) $transactions->first()->old_balance)->toBe(10)
        ->and((int) $transactions->first()->new_balance)->toBe(3)
        ->and((int) $transactions->first()->quantity_delta)->toBe(-7);
});
