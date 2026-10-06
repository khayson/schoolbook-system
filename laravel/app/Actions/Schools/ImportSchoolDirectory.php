<?php

namespace App\Actions\Schools;

use App\Models\DirectorySchool;
use App\Services\Schools\OsmSchoolMapper;
use Illuminate\Support\Facades\DB;

/**
 * Loads one region's schools from an Overpass response (docs/school-directory.md) into the
 * directory. Re-running is safe: entries are matched by their OpenStreetMap reference,
 * changes are updated, entries no longer in OpenStreetMap are marked withdrawn (kept, and
 * still linked to their customer), and nothing touches customers.
 *
 * The response is the stream of `ImportSchoolDirectoryCommand::query()`: each district
 * area followed by its schools, then the region's area and all its schools (a school
 * outside every district gets no district; any area other than a district resets it).
 * Only basic schools are kept (creche, KG, primary, JHS): senior high schools, colleges
 * and universities are skipped (OsmSchoolMapper::isBasic), and ones loaded earlier are
 * withdrawn.
 */
class ImportSchoolDirectory
{
    /**
     * @return array{created: int, updated: int, unchanged: int, withdrawn: int, restored: int, skipped_unnamed: int, skipped_not_basic: int, listed: int}
     */
    public function execute(array $response, string $region): array
    {
        $rows = [];
        $unnamed = [];
        $notBasic = [];
        $district = null;
        foreach ($response['elements'] ?? [] as $element) {
            if (($element['type'] ?? null) === 'area') {
                $tags = $element['tags'] ?? [];
                $district = ($tags['admin_level'] ?? null) === '6' ? ($tags['name'] ?? null) : null;

                continue;
            }
            $ref = ($element['type'] ?? '?').'/'.($element['id'] ?? '?');
            $row = OsmSchoolMapper::map($element, $region, $district);
            if ($row === null) {
                $unnamed[$ref] = true;

                continue;
            }
            if (! OsmSchoolMapper::isBasic($row)) {
                $notBasic[$ref] = true;

                continue;
            }
            // The first sighting carries the district; the region-wide pass only adds the rest.
            if (! isset($rows[$ref]) || ($rows[$ref]['district'] === null && $row['district'] !== null)) {
                $rows[$ref] = $row;
            }
        }

        return DB::transaction(function () use ($rows, $unnamed, $notBasic, $region) {
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0, 'restored' => 0, 'skipped_unnamed' => count($unnamed), 'skipped_not_basic' => count($notBasic), 'listed' => 0];
            $existing = DirectorySchool::query()->where('source', 'osm')->where('region', $region)->get()->keyBy('source_ref');

            foreach ($rows as $ref => $row) {
                $school = $existing->get($ref) ?? DirectorySchool::query()->where('source_ref', $ref)->first();
                if ($school === null) {
                    DirectorySchool::query()->create($row);
                    $counts['created']++;

                    continue;
                }
                $school->fill($row);
                if ($school->withdrawn_at !== null) {
                    $school->withdrawn_at = null;
                    $counts['restored']++;
                }
                if ($school->isDirty()) {
                    $school->save();
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }
            }

            foreach ($existing as $ref => $school) {
                if (! isset($rows[$ref]) && $school->withdrawn_at === null) {
                    $school->update(['withdrawn_at' => now()]);
                    $counts['withdrawn']++;
                }
            }
            $counts['listed'] = DirectorySchool::query()->listed()->where('region', $region)->count();

            return $counts;
        });
    }
}
