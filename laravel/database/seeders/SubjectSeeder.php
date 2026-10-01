<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'Mathematics',
            'English Language',
            'Science',
            'Our World Our People',
            'Creative Arts',
            'RME',
            'Computing/ICT',
            'Ghanaian Language',
            'French',
            'History',
            'Social Studies',
            'Integrated Science',
            'Career Technology',
        ];

        foreach ($names as $name) {
            $slug = Str::slug($name);

            Subject::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'is_active' => true,
                ],
            );
        }
    }
}
