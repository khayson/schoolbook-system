<?php

namespace App\Services;

use App\DTOs\Pricing\AppliedRuleSummary;
use App\DTOs\Pricing\PricedLine;
use App\DTOs\Pricing\PricedOrder;
use App\DTOs\Pricing\PriceLineInput;
use App\Enums\PriceRuleCustomerScope;
use App\Enums\PriceRuleFloorPolicy;
use App\Enums\PriceRuleScopeType;
use App\Enums\PriceRuleType;
use App\Exceptions\InvalidInputException;
use App\Models\Customer;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PricingService
{
    public function __construct(
        private readonly TaxService $tax,
    ) {}

    /**
     * Price lines through active price rules (§7.3–7.5). Manual override skips line rules.
     *
     * Duplicate lines with the same (product, override price, override reason) are
     * merged into one line with summed quantity, keeping first-appearance order.
     *
     * @param  list<PriceLineInput|array{product_id: int, quantity: int, override_unit_price?: int|null, override_reason?: string|null}>  $lines
     */
    public function priceLines(?Customer $customer, array $lines, CarbonInterface $date): PricedOrder
    {
        if ($lines === []) {
            throw new InvalidInputException('items', 'At least one line item is required.');
        }

        $merged = $this->mergeLines(array_values($lines));
        $productIds = array_values(array_unique(array_map(fn (array $entry): int => $entry['line']->productId, $merged)));
        sort($productIds);

        $products = Product::query()
            ->with(['level'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $allRules = PriceRule::query()
            ->active()
            ->effectiveOn($date)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $lineRules = $allRules->filter(fn (PriceRule $rule): bool => $rule->type->isLineLevel())->values();
        $orderRules = $allRules->filter(fn (PriceRule $rule): bool => $rule->type->isOrderLevel())->values();

        $pricedLines = [];
        $subtotal = 0;
        $warnings = [];

        foreach ($merged as ['line' => $line, 'index' => $index]) {
            $product = $products->get($line->productId);
            if ($product === null) {
                throw new InvalidInputException("items.{$index}.product_id", "Product [{$line->productId}] was not found.");
            }

            if (! $product->is_active) {
                throw new InvalidInputException("items.{$index}.product_id", "Product [{$product->sku}] is inactive.");
            }

            $basePrice = (int) $product->selling_price;
            $unitPrice = $basePrice;
            $isOverridden = false;
            $overrideReason = null;
            /** @var list<AppliedRuleSummary> $appliedRules */
            $appliedRules = [];
            $lineWarnings = [];

            if ($line->overrideUnitPrice !== null) {
                $unitPrice = $line->overrideUnitPrice;
                $isOverridden = true;
                $overrideReason = $line->overrideReason;
            } else {
                $candidates = $this->matchingLineRules($lineRules, $product, $customer, $line->quantity);
                $sorted = $this->sortCandidates($candidates);
                [$unitPrice, $appliedRules, $floorPolicy] = $this->applyLineRules($sorted, $basePrice);

                if ($unitPrice < (int) $product->cost_price) {
                    $lineWarnings = $this->floorWarnings(
                        $floorPolicy,
                        $product,
                        $unitPrice,
                        $index,
                        $lineWarnings,
                    );
                    $warnings = [...$warnings, ...$lineWarnings];
                }
            }

            $discountPerUnit = max(0, $basePrice - $unitPrice);
            $discountAmount = $discountPerUnit * $line->quantity;
            $lineTotal = $unitPrice * $line->quantity;
            $subtotal += $lineTotal;

            $pricedLines[] = new PricedLine(
                productId: $product->id,
                productTitle: $product->title,
                quantity: $line->quantity,
                basePrice: $basePrice,
                unitPrice: $unitPrice,
                discountPerUnit: $discountPerUnit,
                discountAmount: $discountAmount,
                taxAmount: 0,
                lineTotal: $lineTotal,
                unitCost: (int) $product->cost_price,
                appliedRules: $appliedRules,
                warnings: $lineWarnings,
                isPriceOverridden: $isOverridden,
                overrideReason: $overrideReason,
            );
        }

        [$discountTotal, $orderApplied, $orderWarnings] = $this->applyOrderRules($orderRules, $customer, $subtotal);
        $warnings = [...$warnings, ...$orderWarnings];

        $netExclusive = max(0, $subtotal - $discountTotal);
        $taxLines = [];
        $taxTotal = 0;

        if (Setting::getValue('tax_enabled', false)) {
            $taxResult = $this->tax->taxOnSubtotal($netExclusive);
            $taxLines = $taxResult['lines'];
            $taxTotal = $taxResult['tax_total'];
            $pricedLines = $this->allocateLineTax($pricedLines, $taxTotal);
        }

        return new PricedOrder(
            lines: $pricedLines,
            subtotal: $subtotal,
            discountTotal: $discountTotal,
            taxTotal: $taxTotal,
            total: $netExclusive + $taxTotal,
            warnings: $warnings,
            taxLines: $taxLines,
        );
    }

    /**
     * Spread order tax across lines by line_total share (largest-remainder so sums match).
     *
     * @param  list<PricedLine>  $lines
     * @return list<PricedLine>
     */
    private function allocateLineTax(array $lines, int $taxTotal): array
    {
        if ($lines === [] || $taxTotal <= 0) {
            return $lines;
        }

        $lineSum = array_sum(array_map(fn (PricedLine $line): int => $line->lineTotal, $lines));
        if ($lineSum <= 0) {
            return $lines;
        }

        $shares = [];
        $allocated = 0;
        foreach ($lines as $index => $line) {
            $exact = ($taxTotal * $line->lineTotal) / $lineSum;
            $floor = (int) floor($exact);
            $shares[] = ['index' => $index, 'amount' => $floor, 'frac' => $exact - $floor];
            $allocated += $floor;
        }

        $remainder = $taxTotal - $allocated;
        usort($shares, fn (array $a, array $b): int => $b['frac'] <=> $a['frac'] ?: $a['index'] <=> $b['index']);
        for ($i = 0; $i < $remainder; $i++) {
            $shares[$i]['amount']++;
        }

        $byIndex = [];
        foreach ($shares as $share) {
            $byIndex[$share['index']] = $share['amount'];
        }

        $out = [];
        foreach ($lines as $index => $line) {
            $out[] = new PricedLine(
                productId: $line->productId,
                productTitle: $line->productTitle,
                quantity: $line->quantity,
                basePrice: $line->basePrice,
                unitPrice: $line->unitPrice,
                discountPerUnit: $line->discountPerUnit,
                discountAmount: $line->discountAmount,
                taxAmount: $byIndex[$index] ?? 0,
                lineTotal: $line->lineTotal,
                unitCost: $line->unitCost,
                appliedRules: $line->appliedRules,
                warnings: $line->warnings,
                isPriceOverridden: $line->isPriceOverridden,
                overrideReason: $line->overrideReason,
            );
        }

        return $out;
    }

    /**
     * @param  Collection<int, PriceRule>  $rules
     * @return Collection<int, PriceRule>
     */
    private function matchingLineRules(Collection $rules, Product $product, ?Customer $customer, int $quantity): Collection
    {
        return $rules->filter(function (PriceRule $rule) use ($product, $customer, $quantity): bool {
            if (! $this->matchesProductScope($rule, $product)) {
                return false;
            }
            if (! $this->matchesCustomerScope($rule, $customer)) {
                return false;
            }
            if ($rule->min_quantity !== null && $quantity < $rule->min_quantity) {
                return false;
            }
            if ($rule->max_quantity !== null && $quantity > $rule->max_quantity) {
                return false;
            }

            return true;
        })->values();
    }

    private function matchesProductScope(PriceRule $rule, Product $product): bool
    {
        return match ($rule->scope_type) {
            PriceRuleScopeType::All => true,
            PriceRuleScopeType::Product => $rule->scope_id === $product->id,
            PriceRuleScopeType::Publisher => $rule->scope_id === $product->publisher_id,
            PriceRuleScopeType::Subject => $rule->scope_id === $product->subject_id,
            PriceRuleScopeType::Language => $rule->scope_id === $product->language_id,
            PriceRuleScopeType::Level => $rule->scope_id === $product->level_id,
            PriceRuleScopeType::LevelGroup => $rule->scope_id === $product->level?->level_group_id,
        };
    }

    private function matchesCustomerScope(PriceRule $rule, ?Customer $customer): bool
    {
        return match ($rule->customer_scope) {
            PriceRuleCustomerScope::All => true,
            PriceRuleCustomerScope::Customer => $customer !== null
                && (string) $rule->customer_scope_value === (string) $customer->id,
            PriceRuleCustomerScope::CustomerType => $customer !== null
                && $rule->customer_scope_value === $customer->type->value,
            PriceRuleCustomerScope::Region => $customer !== null
                && $customer->region !== null
                && $rule->customer_scope_value === $customer->region->value,
        };
    }

    /**
     * Sort by priority desc, specificity desc, id asc.
     *
     * @param  Collection<int, PriceRule>  $candidates
     * @return list<PriceRule>
     */
    private function sortCandidates(Collection $candidates): array
    {
        return $candidates->sort(function (PriceRule $a, PriceRule $b): int {
            if ($a->priority !== $b->priority) {
                return $b->priority <=> $a->priority;
            }
            $spec = $b->specificity() <=> $a->specificity();
            if ($spec !== 0) {
                return $spec;
            }

            return $a->id <=> $b->id;
        })->values()->all();
    }

    /**
     * Apply first rule; if stackable continue only with further stackable rules until a non-stackable is reached.
     *
     * @param  list<PriceRule>  $sorted
     * @return array{0: int, 1: list<AppliedRuleSummary>, 2: PriceRuleFloorPolicy}
     */
    private function applyLineRules(array $sorted, int $basePrice): array
    {
        $unitPrice = $basePrice;
        $applied = [];
        $floor = PriceRuleFloorPolicy::Allow;
        $started = false;

        foreach ($sorted as $rule) {
            if ($started && ! $rule->stackable) {
                break;
            }

            $before = $unitPrice;
            $unitPrice = $this->applyLineEffect($rule, $unitPrice);
            $applied[] = new AppliedRuleSummary(
                id: $rule->id,
                name: $rule->name,
                effect: [
                    'type' => $rule->type->value,
                    'value' => $rule->value,
                    'unit_price_before' => $before,
                    'unit_price_after' => $unitPrice,
                ],
            );
            $floor = PriceRuleFloorPolicy::strictest($floor, $rule->floor_policy);
            $started = true;

            if (! $rule->stackable) {
                break;
            }
        }

        return [$unitPrice, $applied, $floor];
    }

    private function applyLineEffect(PriceRule $rule, int $unitPrice): int
    {
        return match ($rule->type) {
            PriceRuleType::FixedPrice => max(0, $rule->value),
            PriceRuleType::PercentOff => $this->percentOff($unitPrice, $rule->value),
            PriceRuleType::AmountOff => max(0, $unitPrice - $rule->value),
            default => $unitPrice,
        };
    }

    /** Half-up rounding of (price * (10000 - bps) / 10000). */
    private function percentOff(int $amount, int $basisPoints): int
    {
        $bps = min(10000, max(0, $basisPoints));
        $remaining = 10000 - $bps;

        return (int) intdiv(($amount * $remaining) + 5000, 10000);
    }

    /**
     * @param  list<string>  $existing
     * @return list<string>
     */
    private function floorWarnings(
        PriceRuleFloorPolicy $policy,
        Product $product,
        int $unitPrice,
        int $index,
        array $existing,
    ): array {
        $cost = (int) $product->cost_price;

        return match ($policy) {
            PriceRuleFloorPolicy::BlockBelowCost => throw new InvalidInputException(
                "items.{$index}.unit_price",
                "Priced unit ({$unitPrice}) is below cost ({$cost}) for [{$product->sku}] and floor policy blocks it.",
            ),
            PriceRuleFloorPolicy::Warn => [
                ...$existing,
                "Unit price {$unitPrice} is below cost {$cost} for product [{$product->sku}].",
            ],
            PriceRuleFloorPolicy::Allow => $existing,
        };
    }

    /**
     * @param  Collection<int, PriceRule>  $orderRules
     * @return array{0: int, 1: list<AppliedRuleSummary>, 2: list<string>}
     */
    private function applyOrderRules(Collection $orderRules, ?Customer $customer, int $subtotal): array
    {
        $candidates = $orderRules->filter(function (PriceRule $rule) use ($customer, $subtotal): bool {
            if (! $this->matchesCustomerScope($rule, $customer)) {
                return false;
            }
            if ($rule->min_order_total !== null && $subtotal < $rule->min_order_total) {
                return false;
            }

            return true;
        })->values();

        $sorted = $this->sortCandidates($candidates);
        $discount = 0;
        $applied = [];
        $warnings = [];
        $started = false;
        $running = $subtotal;

        foreach ($sorted as $rule) {
            if ($started && ! $rule->stackable) {
                break;
            }

            $before = $discount;
            $chunk = match ($rule->type) {
                PriceRuleType::OrderPercentOff => $this->percentOffAmount($running, $rule->value),
                PriceRuleType::OrderAmountOff => min($running, $rule->value),
                default => 0,
            };
            $discount += $chunk;
            $running = max(0, $subtotal - $discount);

            $applied[] = new AppliedRuleSummary(
                id: $rule->id,
                name: $rule->name,
                effect: [
                    'type' => $rule->type->value,
                    'value' => $rule->value,
                    'discount_before' => $before,
                    'discount_after' => $discount,
                ],
            );
            $started = true;

            if (! $rule->stackable) {
                break;
            }
        }

        return [min($discount, $subtotal), $applied, $warnings];
    }

    /** Half-up: amount * bps / 10000. */
    private function percentOffAmount(int $amount, int $basisPoints): int
    {
        $bps = min(10000, max(0, $basisPoints));

        return (int) intdiv(($amount * $bps) + 5000, 10000);
    }

    /**
     * Validate each input line, then merge duplicates by (product, override price, reason).
     *
     * @param  list<PriceLineInput|array<string, mixed>>  $lines
     * @return list<array{line: PriceLineInput, index: int}> index = first input position, for error keys
     */
    private function mergeLines(array $lines): array
    {
        /** @var array<string, array{line: PriceLineInput, index: int}> $merged */
        $merged = [];

        foreach ($lines as $index => $raw) {
            $line = $this->normalizeLine($raw, $index);
            $key = implode('|', [$line->productId, $line->overrideUnitPrice ?? '', $line->overrideReason ?? '']);

            if (! isset($merged[$key])) {
                $merged[$key] = ['line' => $line, 'index' => $index];

                continue;
            }

            $existing = $merged[$key]['line'];
            $merged[$key]['line'] = new PriceLineInput(
                productId: $existing->productId,
                quantity: $existing->quantity + $line->quantity,
                overrideUnitPrice: $existing->overrideUnitPrice,
                overrideReason: $existing->overrideReason,
            );
        }

        return array_values($merged);
    }

    /**
     * @param  PriceLineInput|array<string, mixed>  $line
     */
    private function normalizeLine(PriceLineInput|array $line, int $index): PriceLineInput
    {
        if (is_array($line)) {
            if (! isset($line['product_id']) || ! is_numeric($line['product_id'])) {
                throw new InvalidInputException("items.{$index}.product_id", 'Each line needs a valid product_id.');
            }

            $line = new PriceLineInput(
                productId: (int) $line['product_id'],
                quantity: (int) ($line['quantity'] ?? 0),
                overrideUnitPrice: isset($line['override_unit_price']) ? (int) $line['override_unit_price'] : null,
                overrideReason: isset($line['override_reason']) ? (string) $line['override_reason'] : null,
            );
        }

        if ($line->quantity <= 0) {
            throw new InvalidInputException("items.{$index}.quantity", 'Line quantity must be greater than zero.');
        }

        if ($line->overrideUnitPrice === null) {
            return $line->overrideReason === null ? $line : new PriceLineInput($line->productId, $line->quantity);
        }

        if ($line->overrideUnitPrice < 0) {
            throw new InvalidInputException("items.{$index}.override_unit_price", 'Unit price cannot be negative.');
        }

        $reason = trim((string) $line->overrideReason);
        if ($reason === '') {
            throw new InvalidInputException("items.{$index}.override_reason", 'Override reason is required when overriding unit price.');
        }

        return new PriceLineInput($line->productId, $line->quantity, $line->overrideUnitPrice, $reason);
    }
}
