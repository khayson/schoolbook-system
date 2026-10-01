<?php

namespace Database\Seeders;

use App\Models\Level;
use App\Models\LevelGroup;
use Illuminate\Database\Seeder;

class LevelSeeder extends Seeder
{
    public function run(): void
    {
        $creche = LevelGroup::query()->where('slug', 'creche')->firstOrFail();
        $kg = LevelGroup::query()->where('slug', 'kg')->firstOrFail();
        $primary = LevelGroup::query()->where('slug', 'primary')->firstOrFail();
        $jhs = LevelGroup::query()->where('slug', 'jhs')->firstOrFail();

        $levels = [
            ['level_group_id' => $creche->id, 'name' => 'Creche', 'slug' => 'creche', 'sort_order' => 1],
            ['level_group_id' => $kg->id, 'name' => 'KG 1', 'slug' => 'kg-1', 'sort_order' => 1],
            ['level_group_id' => $kg->id, 'name' => 'KG 2', 'slug' => 'kg-2', 'sort_order' => 2],
        ];

        for ($i = 1; $i <= 6; $i++) {
            $levels[] = [
                'level_group_id' => $primary->id,
                'name' => "Primary {$i}",
                'slug' => "primary-{$i}",
                'sort_order' => $i,
            ];
        }

        for ($i = 1; $i <= 3; $i++) {
            $levels[] = [
                'level_group_id' => $jhs->id,
                'name' => "JHS {$i}",
                'slug' => "jhs-{$i}",
                'sort_order' => $i,
            ];
        }

        foreach ($levels as $level) {
            Level::query()->updateOrCreate(
                ['slug' => $level['slug']],
                $level,
            );
        }
    }
}
