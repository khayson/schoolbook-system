<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Actions\Payments\RecordPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Schemas\PaymentForm;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePayment extends CreateRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = PaymentResource::class;

    protected static ?string $title = 'Record payment';

    protected static bool $canCreateAnother = false;

    /**
     * ?customer_id= preselects the customer (links from a sale or customer page).
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        if (is_numeric($customerId = request()->query('customer_id'))) {
            $this->data['customer_id'] = (int) $customerId;
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DomainErrorNotifier::attempt(fn () => app(RecordPayment::class)->execute(
            $this->getUser(),
            PaymentForm::toActionData($data, (int) $data['customer_id']),
        ));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "Payment {$this->getRecord()->receipt_no} recorded";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
