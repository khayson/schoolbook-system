<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Actions\Payments\RenderReceiptPdf;
use App\Actions\Payments\VoidPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewPayment extends ViewRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadReceipt')
                ->label('Download receipt')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (Payment $record): bool => $record->isValid())
                ->action(fn (Payment $record): StreamedResponse => self::download($record)),
            Action::make('void')
                ->label('Void payment')
                ->color('danger')
                ->icon('heroicon-o-no-symbol')
                ->visible(fn (Payment $record): bool => $record->isValid())
                ->modalDescription('Every invoice this payment paid will owe that money again, and its credit is removed. This cannot be undone.')
                ->schema([
                    Textarea::make('reason')->required()->maxLength(1000),
                ])
                ->action(function (array $data, Payment $record, Action $action): void {
                    DomainErrorNotifier::attempt(
                        fn () => app(VoidPayment::class)->execute($this->getUser(), $record, $data['reason']),
                        $action,
                    );

                    $this->record->refresh();
                    Notification::make()->title("Payment {$record->receipt_no} voided")->success()->send();
                }),
        ];
    }

    public static function download(Payment $payment): StreamedResponse
    {
        $render = app(RenderReceiptPdf::class);
        $pdf = DomainErrorNotifier::attempt(fn () => $render->execute($payment));

        return response()->streamDownload(fn () => print ($pdf->output()), $render->filename($payment), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
