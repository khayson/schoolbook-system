<?php

namespace App\Actions\Inventory;

use App\Exceptions\InvalidInputException;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Services\NumberSequenceService;
use Illuminate\Support\Facades\DB;

/**
 * Opens a stock-take for all active products, or those matching a level, subject,
 * language and/or publisher filter (snapshot kept on the count). Reference CNT-YYYY-n.
 */
class CreateStockCount
{
    public const FILTERS = ['level_id', 'subject_id', 'language_id', 'publisher_id'];

    public function __construct(private readonly NumberSequenceService $sequences) {}

    /**
     * @param  array{filters?: array<string, int|null>|null, notes?: string|null}  $data
     */
    public function execute(User $user, array $data): StockCount
    {
        $filters = array_filter(
            array_intersect_key($data['filters'] ?? [], array_flip(self::FILTERS)),
            fn ($v) => $v !== null,
        );

        return DB::transaction(function () use ($user, $filters, $data) {
            $productIds = Product::query()
                ->where('is_active', true)
                ->where($filters)
                ->orderBy('id')
                ->pluck('id');

            if ($productIds->isEmpty()) {
                throw new InvalidInputException('filters', 'No active products match this count.');
            }

            $year = (int) now()->format('Y');
            $reference = $this->sequences->format('CNT', $year, $this->sequences->next('cnt', $year));

            $count = StockCount::query()->create([
                'reference' => $reference,
                'status' => StockCount::OPEN,
                'filters' => $filters === [] ? null : $filters,
                'notes' => $data['notes'] ?? null,
                'counted_by' => $user->id,
            ]);

            $now = now();
            StockCountItem::query()->insert($productIds->map(fn (int $id) => [
                'stock_count_id' => $count->id,
                'product_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            return $count->load('items.product');
        });
    }
}
