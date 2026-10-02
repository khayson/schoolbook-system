<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Actions\Sales\CreateDraftSale;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\Sales\Schemas\SaleForm;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSale extends CreateRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = SaleResource::class;

    protected static ?string $title = 'New draft sale';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return DomainErrorNotifier::attempt(fn () => app(CreateDraftSale::class)->execute($this->getUser(), [
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
}
