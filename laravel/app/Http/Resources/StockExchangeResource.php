<?php

namespace App\Http\Resources;

use App\Models\StockExchange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockExchange */
class StockExchangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exchange_no' => $this->exchange_no,
            'customer_id' => $this->customer_id,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'supplier_id' => $this->supplier_id,
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'counterparty_name' => $this->counterparty_name,
            'status' => $this->status?->value,
            'settlement' => $this->settlement?->value,
            'settlement_label' => $this->settlement?->label(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'sale_id' => $this->sale_id,
            'sale' => SaleResource::make($this->whenLoaded('sale')),
            'goods_receipt_id' => $this->goods_receipt_id,
            'goods_receipt' => GoodsReceiptResource::make($this->whenLoaded('goodsReceipt')),
            'confirmed_at' => $this->confirmed_at,
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'items' => StockExchangeItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
