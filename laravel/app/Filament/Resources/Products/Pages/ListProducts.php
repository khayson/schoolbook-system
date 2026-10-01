<?php

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Catalog\ImportProductsFromCsv;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    FileUpload::make('file')
                        ->label('CSV file')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                        ])
                        ->required()
                        ->storeFiles(false),
                ])
                ->action(function (array $data): void {
                    $upload = $data['file'];
                    $path = is_string($upload) ? $upload : $upload->getRealPath();
                    $content = file_get_contents($path);

                    if ($content === false) {
                        Notification::make()
                            ->title('Import failed')
                            ->body('Could not read the uploaded file.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $result = app(ImportProductsFromCsv::class)->execute($this->getUser(), $content);
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()
                            ->title('Import failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Import complete')
                        ->body("Created {$result['created']} products. Opening stock applied for {$result['received_lines']} line(s).")
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
