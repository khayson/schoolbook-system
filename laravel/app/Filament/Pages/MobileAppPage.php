<?php

namespace App\Filament\Pages;

use App\Actions\Users\SetHostSetupPin;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class MobileAppPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $navigationLabel = 'Get the app';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Get the app';

    public const APK_DISK_PATH = 'releases/app-release.apk';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $pinConfigured = app(SetHostSetupPin::class)->isConfigured();

        return $schema
            ->components([
                Section::make('Host setup PIN')
                    ->description($pinConfigured
                        ? 'A PIN is set on this server. Phones use it to unlock Host (More → long-press title). Reset anytime below — no APK rebuild needed.'
                        : 'No server PIN yet. Phones still accept the APK’s built-in PIN until you set one here.')
                    ->schema([
                        TextInput::make('host_setup_pin')
                            ->label('New host PIN')
                            ->password()
                            ->revealable()
                            ->minLength(6)
                            ->helperText('At least 6 characters. Tap the eye to show it.'),
                        TextInput::make('host_setup_pin_confirmation')
                            ->label('Confirm host PIN')
                            ->password()
                            ->revealable()
                            ->same('host_setup_pin'),
                    ]),
                Section::make('Replace build')
                    ->description('Owner only — when you ship a new APK. Cashiers never use this; they use Download / the share link above.')
                    ->collapsed()
                    ->schema([
                        FileUpload::make('apk')
                            ->label('New release APK')
                            ->acceptedFileTypes([
                                'application/vnd.android.package-archive',
                                'application/octet-stream',
                            ])
                            ->disk('local')
                            ->directory('releases')
                            ->visibility('private')
                            ->getUploadedFileNameForStorageUsing(fn (): string => 'app-release.apk')
                            ->helperText('Drops in as schoolbook-release.apk for cashiers after Save.'),
                    ]),
            ]);
    }

    public function saveUpload(): void
    {
        $state = $this->form->getState();

        $pin = trim((string) ($state['host_setup_pin'] ?? ''));
        if ($pin !== '') {
            try {
                app(SetHostSetupPin::class)->execute(
                    $pin,
                    (string) ($state['host_setup_pin_confirmation'] ?? ''),
                );
            } catch (ValidationException $e) {
                throw $e;
            }
        }

        $uploaded = $state['apk'] ?? null;
        if (is_string($uploaded) && $uploaded !== '' && $uploaded !== self::APK_DISK_PATH) {
            if (Storage::disk('local')->exists($uploaded)) {
                if (Storage::disk('local')->exists(self::APK_DISK_PATH)) {
                    Storage::disk('local')->delete(self::APK_DISK_PATH);
                }
                Storage::disk('local')->move($uploaded, self::APK_DISK_PATH);
            }
        }

        $this->form->fill([]);

        Notification::make()
            ->title($pin !== '' ? 'Saved (host PIN updated)' : 'Saved')
            ->success()
            ->send();
    }

    public function clearHostPin(): void
    {
        app(SetHostSetupPin::class)->clear();
        Notification::make()
            ->title('Host PIN cleared')
            ->body('Phones will use the APK’s built-in PIN again until you set a new one.')
            ->success()
            ->send();
    }

    public function content(Schema $schema): Schema
    {
        $apkReady = Storage::disk('local')->exists(self::APK_DISK_PATH);
        $size = $apkReady ? Storage::disk('local')->size(self::APK_DISK_PATH) : null;
        $updated = $apkReady ? Storage::disk('local')->lastModified(self::APK_DISK_PATH) : null;

        $hostUrl = rtrim((string) config('app.url'), '/');

        $signedDownloadUrl = $apkReady
            ? URL::temporarySignedRoute(
                'mobile.apk.signed-download',
                now()->addDays(7),
            )
            : null;

        return $schema
            ->components([
                View::make('filament.pages.mobile-app')
                    ->viewData([
                        'apkReady' => $apkReady,
                        'apkSizeLabel' => $size !== null ? number_format($size / 1048576, 1).' MB' : null,
                        'apkUpdatedLabel' => $updated ? date('Y-m-d H:i', $updated) : null,
                        'hostUrl' => $hostUrl,
                        'signedDownloadUrl' => $signedDownloadUrl,
                        'hostPinConfigured' => app(SetHostSetupPin::class)->isConfigured(),
                    ]),
                Form::make([EmbeddedSchema::make('form')])
                    ->id('mobile-app-form')
                    ->livewireSubmitHandler('saveUpload')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save')
                                ->submit('saveUpload'),
                            Action::make('clearPin')
                                ->label('Clear host PIN')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->visible(fn (): bool => app(SetHostSetupPin::class)->isConfigured())
                                ->action(fn () => $this->clearHostPin()),
                        ])
                            ->alignment(Alignment::Start),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $apkReady = Storage::disk('local')->exists(self::APK_DISK_PATH);

        return [
            Action::make('download')
                ->label('Download APK')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route('admin.mobile-app.download-apk'))
                ->openUrlInNewTab()
                ->visible($apkReady),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isOwner() && $user->is_active;
    }
}
