<?php

/**
 * Concurrent VoidSale worker for MySQL lock tests (Windows-safe via proc_open).
 *
 * Args: sale_id user_id [start_at_unix_float]
 * Loads the sale outside any transaction (as route binding does), waits until
 * start_at, then voids. Exit codes: 0 voided, 4 sale_not_editable,
 * 5 sale_state_conflict, 1 anything else.
 */

use App\Actions\Sales\VoidSale;
use App\Exceptions\SaleNotEditableException;
use App\Exceptions\SaleStateConflictException;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.default' => 'mysql_testing']);

$saleId = (int) ($argv[1] ?? 0);
$userId = (int) ($argv[2] ?? 0);
$startAt = (float) ($argv[3] ?? 0);

if ($saleId <= 0 || $userId <= 0) {
    fwrite(STDERR, "usage: void_sale_worker.php sale_id user_id [start_at]\n");
    exit(2);
}

$user = User::query()->findOrFail($userId);
$sale = Sale::query()->findOrFail($saleId);

$wait = $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    app(VoidSale::class)->execute($user, $sale, 'Concurrency test');
    fwrite(STDOUT, "voided\n");
    exit(0);
} catch (SaleNotEditableException) {
    fwrite(STDOUT, "sale_not_editable\n");
    exit(4);
} catch (SaleStateConflictException) {
    fwrite(STDOUT, "sale_state_conflict\n");
    exit(5);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
