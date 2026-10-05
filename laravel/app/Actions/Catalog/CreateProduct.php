<?php

namespace App\Actions\Catalog;

use App\Actions\Inventory\ReceiveStock;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductSkuGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Creates a product (API, admin, add-from-list). Input is validated already and
 * prefilled from its approved title (PrefillFromReferenceBook). A missing SKU is
 * generated; opening stock comes in through ReceiveStock at the cost price, so the
 * stock ledger stays the only way stock changes.
 */
class CreateProduct
{
    public function __construct(
        private readonly ReceiveStock $receiveStock,
        private readonly ProductSkuGenerator $skus,
    ) {}

    /**
     * @param  array<string, mixed>  $data  product attributes, plus optional opening_stock
     */
    public function execute(User $user, array $data): Product
    {
        return DB::transaction(function () use ($user, $data): Product {
            $openingStock = (int) ($data['opening_stock'] ?? 0);
            unset($data['opening_stock'], $data['stock_on_hand']);

            $data['sku'] = trim((string) ($data['sku'] ?? '')) ?: $this->skus->next();
            $data['is_active'] ??= true;
            $data['reorder_level'] ??= 0;

            $product = Product::query()->create($data);

            if ($openingStock > 0) {
                $this->receiveStock->execute($user, [
                    'received_at' => now(),
                    'notes' => 'Opening stock',
                    'items' => [[
                        'product_id' => $product->id,
                        'quantity' => $openingStock,
                        'unit_cost' => $product->cost_price,
                    ]],
                ]);
            }

            return $product->refresh()->load(['level', 'subject', 'language', 'publisher', 'referenceBook']);
        });
    }
}
