<?php

/**
 * Concurrent money-action worker for MySQL tests (Windows-safe via proc_open).
 *
 *   ledger_worker.php record_payment <customer_id> <user_id> <amount> <start_at> [allocate_to_sale_id|0] [method] [reference]
 *   ledger_worker.php void_sale      <sale_id>     <user_id> <start_at>
 *   ledger_worker.php confirm        <sale_id>     <user_id> <start_at>
 *
 * Loads models outside any transaction (as route binding does), waits until start_at,
 * then runs the action. Prints one line. Exit codes:
 *   0 ok, 6 credit_limit_exceeded, 7 deadlock (1213) or lock wait timeout (1205),
 *   8 any other ApiDomainException (code printed), 1 anything else.
 */

use App\Actions\Payments\RecordPayment;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\VoidSale;
use App\Exceptions\ApiDomainException;
use App\Exceptions\CreditLimitExceededException;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.default' => 'mysql_testing']);

$action = $argv[1] ?? '';

$waitUntil = function (float $startAt): void {
    $wait = $startAt - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1_000_000));
    }
};

try {
    switch ($action) {
        case 'record_payment':
            $customer = Customer::query()->findOrFail((int) $argv[2]);
            $user = User::query()->findOrFail((int) $argv[3]);
            $amount = (int) $argv[4];
            $saleId = isset($argv[6]) && (int) $argv[6] > 0 ? (int) $argv[6] : null;
            $method = $argv[7] ?? 'cash';
            $reference = $argv[8] ?? null;
            $waitUntil((float) $argv[5]);

            $payment = app(RecordPayment::class)->execute($user, array_filter([
                'customer_id' => $customer->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'allocations' => $saleId ? [['sale_id' => $saleId, 'amount' => $amount]] : null,
            ], fn ($v) => $v !== null));
            fwrite(STDOUT, $payment->receipt_no."\n");
            exit(0);

        case 'void_sale':
            $sale = Sale::query()->findOrFail((int) $argv[2]);
            $user = User::query()->findOrFail((int) $argv[3]);
            $waitUntil((float) $argv[4]);

            app(VoidSale::class)->execute($user, $sale, 'Concurrency test');
            fwrite(STDOUT, "voided\n");
            exit(0);

        case 'confirm':
            $sale = Sale::query()->findOrFail((int) $argv[2]);
            $user = User::query()->findOrFail((int) $argv[3]);
            $waitUntil((float) $argv[4]);

            $confirmed = app(ConfirmSale::class)->execute($user, $sale);
            fwrite(STDOUT, $confirmed->invoice_no."\n");
            exit(0);

        default:
            fwrite(STDERR, "unknown action [{$action}]\n");
            exit(2);
    }
} catch (CreditLimitExceededException) {
    fwrite(STDOUT, "credit_limit_exceeded\n");
    exit(6);
} catch (ApiDomainException $e) {
    fwrite(STDOUT, $e->errorCode()."\n");
    exit(8);
} catch (QueryException $e) {
    $code = (int) ($e->errorInfo[1] ?? 0);
    if (in_array($code, [1213, 1205], true)) {
        fwrite(STDOUT, "lock_error_{$code}\n");
        exit(7);
    }
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
