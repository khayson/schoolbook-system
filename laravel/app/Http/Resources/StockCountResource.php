<?php

namespace App\Http\Resources;

use App\Actions\Inventory\StockCountTotals;
use App\Models\StockCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockCount */
class StockCountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'filters' => $this->filters ?? (object) [],
            'notes' => $this->notes,
            'counted_by' => $this->counted_by,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'applied_by' => $this->applied_by,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'totals' => $this->whenLoaded('items', fn () => app(StockCountTotals::class)->run($this->resource)),
            'items' => $this->whenLoaded('items', fn () => $this->items->sortBy('product.sku')->values()->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->product?->sku,
                'title' => $item->product?->title,
                'system_qty' => $item->system_qty,
                'counted_qty' => $item->counted_qty,
                'variance' => $item->variance,
                'baseline_movement_id' => $item->baseline_movement_id,
                'counted_at' => $item->counted_at?->toIso8601String(),
                'unit_cost' => $item->unit_cost,
            ])),
        ];
    }
}
