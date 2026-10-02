<?php

namespace App\Filament\Support;

use App\DTOs\Pricing\PricedOrder;
use App\Exceptions\ApiDomainException;
use App\Exceptions\PriceChangedException;
use App\Models\Sale;
use App\Services\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Turns the typed business exceptions (the same ones the API renders) into Filament
 * notifications. Every piece of text is escaped; lines are joined with <br>.
 */
final class DomainErrorNotifier
{
    /**
     * Runs an Action class call. On a business error: notify, then halt so a modal stays
     * open with what the user typed (or a create/edit page stays on its form). Nothing
     * was written: the actions are transactional.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function attempt(Closure $callback, ?Action $action = null, ?Sale $sale = null): mixed
    {
        try {
            return $callback();
        } catch (ApiDomainException $e) {
            self::notify($e, $sale);

            if ($action !== null) {
                $action->halt();
            }

            throw new Halt;
        }
    }

    public static function notify(ApiDomainException $e, ?Sale $sale = null): void
    {
        [$title, $lines] = self::describe($e, $sale);

        $notification = Notification::make()
            ->title($title)
            ->body(new HtmlString(implode('<br>', array_map(fn (string $line): string => e($line), $lines))))
            ->persistent();

        $e->errorCode() === 'credit_limit_exceeded' ? $notification->warning() : $notification->danger();

        $notification->send();
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function describe(ApiDomainException $e, ?Sale $sale = null): array
    {
        $d = $e->details();
        $ghs = fn (?int $pesewas): string => Money::formatGhsGrouped($pesewas ?? 0);

        return match ($e->errorCode()) {
            'price_changed' => ['Prices changed', [
                ...self::priceChanges($e instanceof PriceChangedException ? $e->pricedOrder() : null, $sale),
                'Nothing was confirmed. Use "Re-price draft" to accept the new prices, then confirm again.',
            ]],
            'insufficient_stock' => ['Not enough stock', [
                ...array_map(
                    fn (array $item): string => "{$item['sku']} {$item['title']}: need {$item['requested']}, have {$item['available']}",
                    $d['items'] ?? [],
                ),
                'Nothing was confirmed.',
            ]],
            'credit_limit_exceeded' => ['Over credit limit', [
                "Outstanding {$ghs($d['outstanding'] ?? 0)} + this sale {$ghs($d['sale_total'] ?? 0)}"
                    .(($d['credit_applied'] ?? 0) > 0 ? " - credit applied {$ghs($d['credit_applied'])}" : '')
                    ." = {$ghs($d['projected_balance'] ?? 0)}, limit {$ghs($d['credit_limit'] ?? 0)}.",
                'Tick "Override credit limit" and confirm again to proceed anyway.',
            ]],
            'validation_failed' => ['Check the details', array_values(array_merge(...array_values($e->errors() ?: [[$e->getMessage()]])))],
            'allocation_exceeds_balance' => ['Amount is more than the invoice balance', [
                "{$d['invoice_no']}: balance due {$ghs($d['balance_due'])}, you entered {$ghs($d['requested'])}.",
            ]],
            'allocation_exceeds_payment' => ['Allocations exceed the payment', [
                "Allocated {$ghs($d['allocated_total'])} from a payment of {$ghs($d['amount'])}.",
            ]],
            'allocation_exceeds_credit' => ['Not enough credit', [
                "Requested {$ghs($d['requested'])}, available credit {$ghs($d['credit_balance'])}.",
            ]],
            default => [Str::headline($e->errorCode()), [$e->getMessage()]],
        };
    }

    /**
     * @return list<string>
     */
    private static function priceChanges(?PricedOrder $order, ?Sale $sale): array
    {
        if ($order === null) {
            return [];
        }

        $items = $sale?->items()->orderBy('id')->get()->values();
        $lines = [];

        foreach ($order->lines as $index => $line) {
            $old = $items?->get($index);
            if ($old === null || $old->product_id !== $line->productId) {
                $lines[] = "{$line->productTitle}: now ".Money::formatGhsGrouped($line->unitPrice);

                continue;
            }
            if ($old->unit_price !== $line->unitPrice) {
                $lines[] = "{$line->productTitle}: ".Money::formatGhsGrouped($old->unit_price).' -> '.Money::formatGhsGrouped($line->unitPrice);
            }
        }

        if ($sale !== null) {
            $lines[] = 'Total: '.Money::formatGhsGrouped($sale->total).' -> '.Money::formatGhsGrouped($order->total);
        }

        return $lines;
    }
}
