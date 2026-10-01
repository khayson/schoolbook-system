<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'business_name' => 'Schoolbook Supply',
            'business_address' => '',
            'business_phone' => '',
            'invoice_footer' => '',
            'allow_negative_stock' => false,
            'tax_enabled' => false,
            'default_payment_terms_days' => 30,
        ];

        foreach ($defaults as $key => $value) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        }
    }
}
