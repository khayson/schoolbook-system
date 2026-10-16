<?php

use App\Http\Controllers\Admin\ApkDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Outside Filament's /admin/* catch-all so a 75MB APK is a normal browser download.
Route::get('/downloads/schoolbook-release.apk', [ApkDownloadController::class, 'forOwner'])
    ->middleware(['web', 'auth'])
    ->name('admin.mobile-app.download-apk');

Route::get('/downloads/share/schoolbook-release.apk', [ApkDownloadController::class, 'signed'])
    ->middleware(['web', 'signed'])
    ->name('mobile.apk.signed-download');
