<?php

/**
 * Concurrent ReceiveStock worker for MySQL lock tests (Windows-safe via proc_open).
 *
 * Args: product_id user_id quantity unit_cost [received_at]
 */

use App\Actions\Inventory\ReceiveStock;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.default' => 'mysql_testing']);

$productId = (int) ($argv[1] ?? 0);
$userId = (int) ($argv[2] ?? 0);
$quantity = (int) ($argv[3] ?? 0);
$unitCost = (int) ($argv[4] ?? 0);
$receivedAt = $argv[5] ?? '2026-06-01';

if ($productId <= 0 || $userId <= 0 || $quantity <= 0 || $unitCost < 0) {
    fwrite(STDERR, "usage: receive_stock_worker.php product_id user_id quantity unit_cost [received_at]\n");
    exit(2);
}

$user = User::query()->findOrFail($userId);

app(ReceiveStock::class)->execute($user, [
    'received_at' => $receivedAt,
    'items' => [
        ['product_id' => $productId, 'quantity' => $quantity, 'unit_cost' => $unitCost],
    ],
]);

fwrite(STDOUT, "ok\n");
exit(0);
