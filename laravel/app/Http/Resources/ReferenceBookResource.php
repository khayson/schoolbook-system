<?php

namespace App\Http\Resources;

use App\Models\ReferenceBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReferenceBook */
class ReferenceBookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'title' => $this->title,
            'level_id' => $this->level_id,
            'level' => $this->level?->name ?? $this->level_label,
            'level_label' => $this->level_label,
            'band' => $this->band,
            'subject_id' => $this->subject_id,
            'subject' => $this->subject?->name ?? $this->subject_label,
            'language_id' => $this->language_id,
            'language' => $this->language?->name,
            'publisher_id' => $this->publisher_id,
            'publisher' => $this->publisher?->name ?? $this->publisher_label,
            'author' => $this->author,
            'isbn' => $this->isbn,
            'status' => $this->status,
            'confidence' => $this->confidence,
            // Linked products in the shop (not deleted) and their total stock.
            'products_count' => (int) ($this->products_count ?? 0),
            'stock_on_hand' => (int) ($this->products_sum_stock_on_hand ?? 0),
        ];
    }
}
