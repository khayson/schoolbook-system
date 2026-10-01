<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['name' => 'English', 'code' => 'en'],
            ['name' => 'Twi (Akuapem)', 'code' => 'tw-ak'],
            ['name' => 'Twi (Asante)', 'code' => 'tw-as'],
            ['name' => 'Fante', 'code' => 'fante'],
            ['name' => 'Ga', 'code' => 'ga'],
            ['name' => 'Ewe', 'code' => 'ewe'],
            ['name' => 'Dagbani', 'code' => 'dagbani'],
            ['name' => 'Dagaare', 'code' => 'dagaare'],
            ['name' => 'Gonja', 'code' => 'gonja'],
            ['name' => 'Nzema', 'code' => 'nzema'],
            ['name' => 'Kasem', 'code' => 'kasem'],
            ['name' => 'Ga-Dangme', 'code' => 'ga-dangme'],
            ['name' => 'French', 'code' => 'fr'],
        ];

        foreach ($languages as $language) {
            Language::query()->updateOrCreate(
                ['code' => $language['code']],
                [
                    'name' => $language['name'],
                    'is_active' => true,
                ],
            );
        }
    }
}
