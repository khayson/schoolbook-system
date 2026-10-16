<?php

use App\Filament\Pages\MobileAppPage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->owner()->create(['is_active' => true]);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('mobile app page loads for the owner', function () {
    Livewire::test(MobileAppPage::class)->assertSuccessful();
});

test('signed apk download works without a filament session', function () {
    Storage::disk('local')->put(MobileAppPage::APK_DISK_PATH, 'fake-apk-bytes');

    $url = URL::temporarySignedRoute('mobile.apk.signed-download', now()->addHour());

    $this->get($url)
        ->assertOk()
        ->assertHeader('content-disposition');
});

test('owner can download apk while logged into filament', function () {
    Storage::disk('local')->put(MobileAppPage::APK_DISK_PATH, 'fake-apk-bytes');

    $this->get(route('admin.mobile-app.download-apk'))
        ->assertOk()
        ->assertHeader('content-disposition');
});
