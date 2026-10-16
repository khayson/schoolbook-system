<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\MobileAppPage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApkDownloadController extends Controller
{
    public function forOwner(Request $request): BinaryFileResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user !== null && $user->isOwner() && $user->is_active, 403);

        return $this->download();
    }

    public function signed(): BinaryFileResponse
    {
        return $this->download();
    }

    private function download(): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists(MobileAppPage::APK_DISK_PATH), 404);

        $path = Storage::disk('local')->path(MobileAppPage::APK_DISK_PATH);

        return response()->download($path, 'schoolbook-release.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
