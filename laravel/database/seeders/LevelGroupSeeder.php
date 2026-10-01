<?php

namespace Database\Seeders;

use App\Models\LevelGroup;
use Illuminate\Database\Seeder;

class LevelGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['name' => 'Creche', 'slug' => 'creche', 'sort_order' => 1],
            ['name' => 'KG', 'slug' => 'kg', 'sort_order' => 2],
            ['name' => 'Primary', 'slug' => 'primary', 'sort_order' => 3],
            ['name' => 'JHS', 'slug' => 'jhs', 'sort_order' => 4],
        ];

        foreach ($groups as $group) {
            LevelGroup::query()->updateOrCreate(
                ['slug' => $group['slug']],
                $group,
            );
        }
    }
}
