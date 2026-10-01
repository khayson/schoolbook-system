<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'isbn' => $this->isbn,
            'barcode' => $this->barcode,
            'title' => $this->title,
            'level_id' => $this->level_id,
            'subject_id' => $this->subject_id,
            'language_id' => $this->language_id,
            'publisher_id' => $this->publisher_id,
            'edition' => $this->edition,
            'cost_price' => $this->cost_price,
            'selling_price' => $this->selling_price,
            'reorder_level' => $this->reorder_level,
            'stock_on_hand' => $this->stock_on_hand,
            'is_active' => $this->is_active,
            'level' => new LevelResource($this->whenLoaded('level')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'language' => new LanguageResource($this->whenLoaded('language')),
            'publisher' => new PublisherResource($this->whenLoaded('publisher')),
        ];
    }
}
