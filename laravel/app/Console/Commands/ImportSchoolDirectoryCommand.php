<?php

namespace App\Console\Commands;

use App\Actions\Schools\ImportSchoolDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads the schools of the regions the shop supplies from OpenStreetMap (Overpass
 * API) and loads them into the school directory (docs/school-directory.md). Each
 * download is kept in storage/app/reference/ (git-ignored) and can be re-loaded with
 * --file without going online.
 */
class ImportSchoolDirectoryCommand extends Command
{
    /** ISO 3166-2 code => region as stored on customers (App\Enums\GhanaRegion). */
    public const REGIONS = ['GH-AA' => 'Greater Accra', 'GH-CP' => 'Central'];

    protected $signature = 'schools:import
        {--region=* : GH-AA (Greater Accra) and/or GH-CP (Central); default both}
        {--file= : Load a saved Overpass response instead of downloading (one --region)}';

    protected $description = 'Load the school directory from OpenStreetMap for the regions the shop supplies';

    public function handle(ImportSchoolDirectory $import): int
    {
        $codes = (array) $this->option('region') ?: array_keys(self::REGIONS);
        $unknown = array_diff($codes, array_keys(self::REGIONS));
        if ($unknown !== []) {
            $this->error('Unknown region code(s): '.implode(', ', $unknown).'. Use '.implode(' or ', array_keys(self::REGIONS)).'.');

            return self::FAILURE;
        }
        if ($this->option('file') !== null && count($codes) !== 1) {
            $this->error('--file needs exactly one --region.');

            return self::FAILURE;
        }

        $rows = [];
        foreach ($codes as $code) {
            try {
                $response = $this->option('file') !== null ? $this->readFile((string) $this->option('file')) : $this->download($code);
            } catch (Throwable $e) {
                $this->error("{$code}: {$e->getMessage()}");

                return self::FAILURE;
            }
            $counts = $import->execute($response, self::REGIONS[$code]);
            $rows[] = [self::REGIONS[$code], ...array_values($counts)];
        }

        $this->table(['Region', 'New', 'Updated', 'Unchanged', 'Withdrawn', 'Restored', 'No name (skipped)', 'SHS/tertiary (skipped)', 'In directory'], $rows);
        $this->line('Data © OpenStreetMap contributors, ODbL. OpenStreetMap lists the schools volunteers have mapped, not every school.');

        return self::SUCCESS;
    }

    /**
     * One region: every district area followed by its schools, then the region's own area
     * (which ends the last district) followed by all the region's schools.
     */
    public static function query(string $isoCode): string
    {
        return <<<OVERPASS
            [out:json][timeout:600];
            area["ISO3166-2"="{$isoCode}"]->.r;
            rel(area.r)["boundary"="administrative"]["admin_level"="6"];
            map_to_area->.ds;
            foreach.ds->.d(
              .d out tags;
              nwr(area.d)["amenity"~"^(school|kindergarten)$"];
              out tags center;
            );
            .r out tags;
            nwr(area.r)["amenity"~"^(school|kindergarten)$"];
            out tags center;
            OVERPASS;
    }

    private function download(string $code): array
    {
        $this->line("Downloading {$code} from ".config('services.overpass.url').' (this can take a few minutes)...');
        $response = Http::withUserAgent('SchoolbookSupply/1.0 (school directory for a textbook supplier)')
            ->accept('application/json')
            ->timeout(660)
            ->retry(3, 20000)
            ->asForm()
            ->post((string) config('services.overpass.url'), ['data' => self::query($code)])
            ->throw();
        $data = $response->json();
        if (! is_array($data) || ! isset($data['elements'])) {
            throw new \RuntimeException('Overpass did not return JSON elements (busy? try again later).');
        }
        // Kept beside the NaCCA list in the git-ignored storage/app/reference/.
        $dir = storage_path('app/reference');
        is_dir($dir) || mkdir($dir, 0775, true);
        file_put_contents($dir.'/osm-schools-'.$code.'-'.now()->format('Ymd-His').'.json', $response->body());

        return $data;
    }

    private function readFile(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['elements'])) {
            throw new \RuntimeException('Not an Overpass JSON response.');
        }

        return $data;
    }
}
