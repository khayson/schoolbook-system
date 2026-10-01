<?php

namespace App\Support;

use Illuminate\Support\Str;

class ResolvesSlug
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forName(array $data, string $nameKey = 'name', string $slugKey = 'slug'): array
    {
        if (empty($data[$slugKey]) && ! empty($data[$nameKey])) {
            $data[$slugKey] = Str::slug((string) $data[$nameKey]);
        }

        return $data;
    }
}
