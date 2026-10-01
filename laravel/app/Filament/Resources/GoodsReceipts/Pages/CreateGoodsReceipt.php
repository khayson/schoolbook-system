<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Actions\Inventory\ReceiveStock;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $items = collect($data['items'] ?? [])
            ->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                // Form dehydrates unit_cost to pesewas already.
                'unit_cost' => (int) $item['unit_cost'],
            ])
            ->values()
            ->all();

        return app(ReceiveStock::class)->execute($this->getUser(), [
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_reference' => $data['supplier_reference'] ?? null,
            'received_at' => $data['received_at'],
            'notes' => $data['notes'] ?? null,
            'items' => $items,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
