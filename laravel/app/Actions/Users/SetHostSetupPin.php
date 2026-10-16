<?php

namespace App\Actions\Users;

use App\Models\Setting;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SetHostSetupPin
{
    public const SETTING_KEY = 'mobile_host_setup_pin_hash';

    public function execute(string $pin, string $confirmation): void
    {
        $pin = trim($pin);
        $confirmation = trim($confirmation);

        if (strlen($pin) < 6) {
            throw ValidationException::withMessages([
                'host_setup_pin' => ['PIN must be at least 6 characters.'],
            ]);
        }

        if ($pin !== $confirmation) {
            throw ValidationException::withMessages([
                'host_setup_pin_confirmation' => ['PIN confirmation does not match.'],
            ]);
        }

        Setting::setValue(self::SETTING_KEY, Hash::make($pin));
    }

    public function clear(): void
    {
        Setting::query()->where('key', self::SETTING_KEY)->delete();
    }

    public function isConfigured(): bool
    {
        $hash = Setting::getValue(self::SETTING_KEY);

        return is_string($hash) && $hash !== '';
    }

    public function verify(string $pin): bool
    {
        $hash = Setting::getValue(self::SETTING_KEY);
        if (! is_string($hash) || $hash === '') {
            return false;
        }

        return Hash::check($pin, $hash);
    }
}
