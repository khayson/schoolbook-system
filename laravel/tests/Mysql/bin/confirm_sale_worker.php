<?php

/**
 * Concurrent ConfirmSale worker for MySQL lock tests (Windows-safe via proc_open).
 *
 * Args: sale_id user_id [start_at_unix_float]
 * Waits until start_at so all workers hit the locks together.
 * Exit codes: 0 confirmed, 3 insufficient_stock, 4 sale_not_editable, 1 anything else.
 */

use App\Actions\Sales\ConfirmSale;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\SaleNotEditableException;
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
    fwrite(STDERR, "usage: confirm_sale_worker.php sale_id user_id [start_at]\n");
    exit(2);
}

$user = User::query()->findOrFail($userId);
$sale = Sale::query()->findOrFail($saleId);

$wait = $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    $confirmed = app(ConfirmSale::class)->execute($user, $sale);
    fwrite(STDOUT, $confirmed->invoice_no."\n");
    exit(0);
} catch (InsufficientStockException) {
    fwrite(STDOUT, "insufficient_stock\n");
    exit(3);
} catch (SaleNotEditableException) {
    fwrite(STDOUT, "sale_not_editable\n");
    exit(4);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
