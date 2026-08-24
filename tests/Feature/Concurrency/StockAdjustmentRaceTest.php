<?php

/*
 * Phase 4 test #5 — THE race-condition test (written FIRST).
 *
 * stock = 10, two PARALLEL -7 adjustments → exactly one succeeds, one is
 * rejected, final committed stock = 3 and never negative. A naive
 * read-then-write implementation lets both succeed (final = -4 or 3 with a
 * lost update) and MUST fail this test; only lockForUpdate() inside a
 * transaction can pass it.
 *
 * Concurrency mechanism (chosen for this environment and documented per the
 * plan): two independent PHP OS processes via Symfony\Component\Process.
 * Each child boots the full Laravel app against the real PostgreSQL
 * `inventory_testing` database (env vars are injected explicitly so .env dev
 * values cannot leak in — Dotenv never overrides real environment
 * variables), then spins on a shared "go" signal file. The parent only
 * creates the go-file after BOTH children reported ready, guaranteeing the
 * two adjustStock() calls genuinely overlap in time rather than running
 * sequentially. pcntl_fork was rejected because forking a booted PHPUnit
 * process shares the parent's PDO connection, which corrupts both sides.
 *
 * This file lives in tests/Feature/Concurrency and therefore uses
 * DatabaseTruncation (see tests/Pest.php): seeded rows are COMMITTED and
 * visible to the child processes, unlike under RefreshDatabase's wrapping
 * transaction.
 */

use App\Models\Inventory;
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
            echo 'FAIL:timeout-waiting-for-go';
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
        'DB_DATABASE' => $db['database'], // inventory_testing — committed rows are visible here
        'DB_USERNAME' => $db['username'],
        'DB_PASSWORD' => $db['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
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
});

it('serializes two parallel -7 adjustments on stock 10: one succeeds, one is rejected, final stock is 3', function () {
    // Committed seed data (DatabaseTruncation — no wrapping transaction).
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

    // Wait until BOTH children have booted and are parked on the start line.
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

    // Fire the shared start signal — both adjustments now run concurrently.
    touch($dir.'/go');

    foreach ($processes as $process) {
        $process->wait();
    }

    $outputs = [
        'a' => trim($processes['a']->getOutput()),
        'b' => trim($processes['b']->getOutput()),
    ];

    $succeeded = array_filter($outputs, fn (string $out) => $out === 'OK');
    $rejected = array_filter($outputs, fn (string $out) => str_starts_with($out, 'FAIL:'));

    expect($succeeded)->toHaveCount(1, 'Exactly one of the two parallel adjustments must succeed. Outputs: '.json_encode($outputs).' Stderr: '.$processes['a']->getErrorOutput().$processes['b']->getErrorOutput())
        ->and($rejected)->toHaveCount(1, 'Exactly one of the two parallel adjustments must be rejected. Outputs: '.json_encode($outputs));

    // Final committed balance: 10 - 7 = 3, and never negative.
    $finalQuantity = (int) Inventory::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('quantity');

    expect($finalQuantity)->toBe(3)
        ->and($finalQuantity)->toBeGreaterThanOrEqual(0);
});
