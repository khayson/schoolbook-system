<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;

class SettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 99;

    protected static ?string $title = 'Settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'business_name' => Setting::getValue('business_name', ''),
            'business_address' => Setting::getValue('business_address', ''),
            'business_phone' => Setting::getValue('business_phone', ''),
            'invoice_footer' => Setting::getValue('invoice_footer', ''),
            'allow_negative_stock' => (bool) Setting::getValue('allow_negative_stock', false),
            'default_payment_terms_days' => (int) Setting::getValue('default_payment_terms_days', 30),
            'tax_enabled' => (bool) Setting::getValue('tax_enabled', false),
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('business_name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('business_address')
                    ->label('Address')
                    ->rows(3)
                    ->columnSpanFull(),
                TextInput::make('business_phone')
                    ->label('Phone')
                    ->tel()
                    ->maxLength(255),
                Textarea::make('invoice_footer')
                    ->rows(3)
                    ->columnSpanFull(),
                Toggle::make('allow_negative_stock')
                    ->label('Allow negative stock'),
                TextInput::make('default_payment_terms_days')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Toggle::make('tax_enabled')
                    ->label('Tax enabled')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::setValue('business_name', $data['business_name']);
        Setting::setValue('business_address', $data['business_address'] ?? '');
        Setting::setValue('business_phone', $data['business_phone'] ?? '');
        Setting::setValue('invoice_footer', $data['invoice_footer'] ?? '');
        Setting::setValue('allow_negative_stock', (bool) ($data['allow_negative_stock'] ?? false));
        Setting::setValue('default_payment_terms_days', (int) $data['default_payment_terms_days']);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('settings-form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save settings')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ])
                            ->alignment(Alignment::Start)
                            ->key('settings-form-actions'),
                    ]),
            ]);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isOwner() && $user->is_active;
    }
}
