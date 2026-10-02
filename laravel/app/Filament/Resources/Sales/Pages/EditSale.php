<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Actions\Sales\UpdateDraftSale;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\Sales\Schemas\SaleForm;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Money;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Drafts only (SaleResource::canEdit). Saving reprices through UpdateDraftSale.
 */
class EditSale extends EditRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = SaleResource::class;

    protected static ?string $title = 'Edit draft sale';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Sale $sale */
        $sale = $this->getRecord();

        $data['items'] = $sale->items()->orderBy('id')->get()->map(fn (SaleItem $item): array => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'override_unit_price' => $item->is_price_overridden ? Money::pesewasToGhs($item->unit_price) : null,
            'override_reason' => $item->override_reason,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Sale $record */
        return DomainErrorNotifier::attempt(fn () => app(UpdateDraftSale::class)->execute($this->getUser(), $record, [
            'customer_id' => (int) $data['customer_id'],
            'sale_date' => $data['sale_date'],
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'items' => SaleForm::linesFromState($data['items'] ?? []),
        ]));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
