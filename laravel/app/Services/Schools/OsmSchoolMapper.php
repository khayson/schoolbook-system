<?php

namespace App\Services\Schools;

use App\Services\Reference\ReferenceMapper;

/**
 * OpenStreetMap school/kindergarten tags → directory fields. Levels and ownership come
 * from tags when present (isced:level, operator:type) and otherwise from words in the
 * name ("KG", "Primary", "JHS", "Basic", "SHS"; "D/A", "M/A" for public schools).
 */
final class OsmSchoolMapper
{
    /** Overpass `out tags center` element + district name → directory row, or null without a name. */
    public static function map(array $element, string $region, ?string $district): ?array
    {
        $tags = $element['tags'] ?? [];
        $name = ReferenceMapper::squish((string) ($tags['name'] ?? $tags['official_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
        $lon = $element['lon'] ?? $element['center']['lon'] ?? null;
        $phone = $tags['phone'] ?? $tags['contact:phone'] ?? null;

        return [
            'source' => 'osm',
            'source_ref' => $element['type'].'/'.$element['id'],
            'name' => mb_substr($name, 0, 255),
            'search_name' => self::searchName($name),
            'region' => $region,
            'district' => $district === null ? null : self::district($district),
            'town' => isset($tags['addr:city']) ? mb_substr(ReferenceMapper::squish($tags['addr:city']), 0, 255) : null,
            'phone' => $phone === null ? null : mb_substr(ReferenceMapper::squish(explode(';', $phone)[0]), 0, 255),
            'levels' => self::levels($name, $tags),
            'ownership' => self::ownership($name, $tags),
            'latitude' => $lat === null ? null : number_format((float) $lat, 7, '.', ''),
            'longitude' => $lon === null ? null : number_format((float) $lon, 7, '.', ''),
        ];
    }

    /**
     * The shop supplies basic schools only (creche, KG, primary, JHS). False for a school
     * that is only senior high, or that is a college, university or institute; a school
     * with any basic level (e.g. "JHS and SHS") is kept, and so is one whose level is
     * unknown (most entries).
     */
    public static function isBasic(array $row): bool
    {
        $levels = $row['levels'] === null ? [] : explode(',', $row['levels']);
        if (array_intersect($levels, ['kindergarten', 'primary', 'jhs']) !== []) {
            return true;
        }
        if ($levels === ['shs']) {
            return false;
        }

        return ! preg_match('/ (university|polytechnic|college|institute|nursing|midwifery|seminary|academy of|vocational|training centre|training center) /', ' '.$row['search_name'].' ');
    }

    /** Lower-case ASCII words: "St. Peter's R/C Basic" → "st peters r c basic". */
    public static function searchName(string $name): string
    {
        $tokens = preg_split('/[^a-z0-9]+/', ReferenceMapper::ascii($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_substr(implode(' ', $tokens), 0, 255);
    }

    /** "Shai-Osudoku District" → "Shai-Osudoku"; municipal and metropolitan names kept whole. */
    public static function district(string $name): string
    {
        return (string) preg_replace('/\s+District$/i', '', ReferenceMapper::squish($name));
    }

    private static function levels(string $name, array $tags): ?string
    {
        $levels = [];
        if (($tags['amenity'] ?? null) === 'kindergarten') {
            $levels[] = 'kindergarten';
        }
        foreach (preg_split('/[;,]/', (string) ($tags['isced:level'] ?? '')) ?: [] as $isced) {
            $levels[] = match (trim($isced)) {
                '0' => 'kindergarten',
                '1' => 'primary',
                '2' => 'jhs',
                '3' => 'shs',
                default => null,
            };
        }
        $words = ' '.self::searchName($name).' ';
        $patterns = [
            'kindergarten' => '/ (kg|kindergarten|nursery|creche|preparatory|prep) /',
            'primary' => '/ (primary|basic|preparatory|prep) /',
            'jhs' => '/ (jhs|junior high|junior secondary|basic) /',
            // "secondary school" only when not "junior secondary school" (the old name for JHS).
            'shs' => '/ (shs|shts|senior high|snr high|senior secondary|(?<!junior )secondary school|(?<!junior )high school|international high|technical institute) /',
        ];
        foreach ($patterns as $level => $pattern) {
            if (preg_match($pattern, $words)) {
                $levels[] = $level;
            }
        }
        $levels = array_values(array_intersect(['kindergarten', 'primary', 'jhs', 'shs'], array_filter($levels)));

        return $levels === [] ? null : implode(',', $levels);
    }

    private static function ownership(string $name, array $tags): ?string
    {
        $type = strtolower((string) ($tags['operator:type'] ?? ''));
        if (in_array($type, ['government', 'public', 'state'], true)) {
            return 'public';
        }
        if (in_array($type, ['private', 'private_non_profit', 'religious', 'community'], true)) {
            return 'private';
        }
        // District / Municipal / Local Authority schools: "D/A", "M/A", "L/A".
        if (preg_match('/\b(D\/A|M\/A|L\/A|District Assembly|Municipal Assembly)\b/i', $name)) {
            return 'public';
        }

        return null;
    }
}
