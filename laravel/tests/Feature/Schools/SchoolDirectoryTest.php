<?php

use App\Actions\Schools\ImportSchoolDirectory;
use App\Filament\Resources\DirectorySchools\Pages\ListDirectorySchools;
use App\Models\Customer;
use App\Models\DirectorySchool;
use App\Models\User;
use App\Services\Schools\OsmSchoolMapper;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
 * Synthetic Overpass responses (made-up schools, same shape as schools:import downloads).
 */

/** @param  list<array>  $districts  [name, [elements]] ; $outside elements with no district */
function overpass(array $districts, array $outside = []): array
{
    $elements = [];
    $all = $outside;
    foreach ($districts as [$name, $schools]) {
        $elements[] = ['type' => 'area', 'id' => 3600000000 + crc32($name) % 1000, 'tags' => ['admin_level' => '6', 'boundary' => 'administrative', 'name' => $name]];
        array_push($elements, ...$schools);
        array_push($all, ...$schools);
    }

    // As the real query: the region's own area (admin_level 4) before the region-wide pass.
    $region = ['type' => 'area', 'id' => 3600000001, 'tags' => ['admin_level' => '4', 'boundary' => 'administrative', 'name' => 'Region']];

    return ['elements' => [...$elements, $region, ...$all]];
}

function osmSchool(string $type, int $id, array $tags, float $lat = 5.55, float $lon = -0.2): array
{
    return $type === 'node'
        ? ['type' => 'node', 'id' => $id, 'lat' => $lat, 'lon' => $lon, 'tags' => $tags]
        : ['type' => $type, 'id' => $id, 'center' => ['lat' => $lat, 'lon' => $lon], 'tags' => $tags];
}

function sampleRegion(): array
{
    return overpass([
        ['Awutu Senya East Municipal District', [
            osmSchool('node', 101, ['amenity' => 'school', 'name' => "St. Peter's R/C Basic School", 'addr:city' => 'Kasoa', 'phone' => '+233 20 000 0001;+233 24 000 0002']),
            osmSchool('way', 202, ['amenity' => 'kindergarten', 'name' => 'Little Stars Creche']),
        ]],
        ['Gomoa East District', [
            osmSchool('node', 303, ['amenity' => 'school', 'name' => 'Gomoa Fetteh D/A Primary and JHS', 'isced:level' => '1;2']),
            osmSchool('node', 404, ['amenity' => 'school']),
        ]],
    ], [
        osmSchool('node', 505, ['amenity' => 'school', 'name' => 'Seaview Senior High School', 'operator:type' => 'private']),
        osmSchool('node', 606, ['amenity' => 'school', 'name' => 'Mankessim Methodist Junior Secondary School']),
    ]);
}

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
});

test('importing a region maps names, districts, levels, ownership, phone and position', function () {
    $counts = app(ImportSchoolDirectory::class)->execute(sampleRegion(), 'Central');

    // Seaview Senior High School is skipped: the directory is creche, KG, primary and JHS only.
    expect($counts)->toBe(['created' => 4, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0, 'restored' => 0, 'skipped_unnamed' => 1, 'skipped_not_basic' => 1, 'listed' => 4]);

    $rows = DirectorySchool::query()->orderBy('source_ref')->get()
        ->map(fn (DirectorySchool $s) => [$s->source_ref, $s->name, $s->district, $s->town, $s->levels, $s->ownership, $s->phone])->all();
    expect($rows)->toBe([
        ['node/101', "St. Peter's R/C Basic School", 'Awutu Senya East Municipal', 'Kasoa', 'primary,jhs', null, '+233 20 000 0001'],
        ['node/303', 'Gomoa Fetteh D/A Primary and JHS', 'Gomoa East', null, 'primary,jhs', 'public', null],
        ['node/606', 'Mankessim Methodist Junior Secondary School', null, null, 'jhs', null, null],
        ['way/202', 'Little Stars Creche', 'Awutu Senya East Municipal', null, 'kindergarten', null, null],
    ])
        ->and(DirectorySchool::query()->where('source_ref', 'node/101')->value('search_name'))->toBe('st peters r c basic school')
        ->and(DirectorySchool::query()->where('source_ref', 'way/202')->first()->only(['latitude', 'longitude', 'region']))
        ->toBe(['latitude' => '5.5500000', 'longitude' => '-0.2000000', 'region' => 'Central']);
});

test('re-importing never duplicates: unchanged, renamed, withdrawn and restored', function () {
    $import = app(ImportSchoolDirectory::class);
    $import->execute(sampleRegion(), 'Central');

    expect($import->execute(sampleRegion(), 'Central'))->toMatchArray(['created' => 0, 'updated' => 0, 'unchanged' => 4, 'withdrawn' => 0]);

    $changed = overpass([
        ['Awutu Senya East Municipal District', [
            osmSchool('node', 101, ['amenity' => 'school', 'name' => "St. Peter's R/C Basic School, Kasoa", 'addr:city' => 'Kasoa', 'phone' => '+233 20 000 0001']),
        ]],
        ['Gomoa East District', [osmSchool('node', 303, ['amenity' => 'school', 'name' => 'Gomoa Fetteh D/A Primary and JHS', 'isced:level' => '1;2'])]],
    ], [osmSchool('node', 606, ['amenity' => 'school', 'name' => 'Mankessim Methodist Junior Secondary School'])]);
    expect($import->execute($changed, 'Central'))->toMatchArray(['created' => 0, 'updated' => 1, 'unchanged' => 2, 'withdrawn' => 1, 'listed' => 3])
        ->and(DirectorySchool::query()->count())->toBe(4)
        ->and(DirectorySchool::query()->where('source_ref', 'way/202')->value('withdrawn_at'))->not->toBeNull();

    expect($import->execute(sampleRegion(), 'Central'))->toMatchArray(['restored' => 1, 'withdrawn' => 0, 'listed' => 4])
        ->and(DirectorySchool::query()->count())->toBe(4);
});

test('schools:import --file loads a saved download and reports it', function () {
    $path = tempnam(sys_get_temp_dir(), 'osm').'.json';
    file_put_contents($path, json_encode(sampleRegion()));

    $this->artisan('schools:import', ['--region' => ['GH-CP'], '--file' => $path])
        ->expectsTable(['Region', 'New', 'Updated', 'Unchanged', 'Withdrawn', 'Restored', 'No name (skipped)', 'SHS/tertiary (skipped)', 'In directory'], [['Central', 4, 0, 0, 0, 0, 1, 1, 4]])
        ->assertSuccessful();

    $this->artisan('schools:import', ['--region' => ['GH-XX']])->assertFailed();
    $this->artisan('schools:import', ['--file' => $path])->assertFailed(); // two regions with one file
    @unlink($path);
});

test('the API searches by name words, district or town and filters region and added', function () {
    app(ImportSchoolDirectory::class)->execute(sampleRegion(), 'Central');
    Sanctum::actingAs($this->owner);

    $names = fn (string $query) => array_column($this->getJson('/api/v1/school-directory?'.$query)->assertOk()->json('data'), 'name');

    expect($names('search=st+peters'))->toBe(["St. Peter's R/C Basic School"])
        ->and($names('search=kasoa'))->toBe(["St. Peter's R/C Basic School"])
        ->and($names('search=gomoa+primary'))->toBe(['Gomoa Fetteh D/A Primary and JHS'])
        ->and($names('district=Awutu+Senya+East+Municipal'))->toBe(['Little Stars Creche', "St. Peter's R/C Basic School"])
        ->and($names('region=Greater+Accra'))->toBe([])
        ->and($names('added=1'))->toBe([]);

    $this->getJson('/api/v1/school-directory')
        ->assertJsonPath('meta.attribution', DirectorySchool::ATTRIBUTION)
        ->assertJsonPath('data.0.level_label', 'Primary, JHS')
        ->assertJsonPath('meta.total', 4);
});

test('adding a directory school creates a prefilled customer once; same-name customers are linked, not duplicated', function () {
    app(ImportSchoolDirectory::class)->execute(sampleRegion(), 'Central');
    Sanctum::actingAs($this->owner);
    $stPeters = DirectorySchool::query()->where('source_ref', 'node/101')->first();

    $this->withHeader('Idempotency-Key', 'add-1')
        ->postJson("/api/v1/school-directory/{$stPeters->id}/customer", ['contact_person' => 'Headteacher'])
        ->assertCreated()
        ->assertJsonPath('data.name', "St. Peter's R/C Basic School")
        ->assertJsonPath('data.type', 'school')
        ->assertJsonPath('data.region', 'Central')
        ->assertJsonPath('data.district', 'Awutu Senya East Municipal')
        ->assertJsonPath('data.phone', '+233 20 000 0001')
        ->assertJsonPath('data.contact_person', 'Headteacher');
    expect($stPeters->fresh()->customer_id)->toBe(Customer::query()->sole()->id);

    $this->withHeader('Idempotency-Key', 'add-2')->postJson("/api/v1/school-directory/{$stPeters->id}/customer")
        ->assertStatus(409)->assertJsonPath('code', 'already_customer');

    // A customer typed in by hand earlier, same name in another case: refused, then linked.
    $gomoa = DirectorySchool::query()->where('source_ref', 'node/303')->first();
    $existing = Customer::factory()->create(['name' => 'GOMOA FETTEH D/A PRIMARY AND JHS']);
    $this->withHeader('Idempotency-Key', 'add-3')->postJson("/api/v1/school-directory/{$gomoa->id}/customer")
        ->assertStatus(409)->assertJsonPath('code', 'customer_name_exists')->assertJsonPath('details.customer_id', $existing->id);
    $this->withHeader('Idempotency-Key', 'add-4')->postJson("/api/v1/school-directory/{$gomoa->id}/customer", ['link_customer_id' => $existing->id])
        ->assertOk()->assertJsonPath('data.id', $existing->id);
    expect($gomoa->fresh()->customer_id)->toBe($existing->id)
        ->and(Customer::query()->count())->toBe(2);

    // One directory entry per customer.
    $seaview = DirectorySchool::query()->where('source_ref', 'node/606')->first();
    $this->withHeader('Idempotency-Key', 'add-5')->postJson("/api/v1/school-directory/{$seaview->id}/customer", ['link_customer_id' => $existing->id])
        ->assertStatus(409)->assertJsonPath('code', 'customer_already_linked');

    $this->getJson('/api/v1/school-directory?added=1')->assertJsonCount(2, 'data');
});

test('the directory is owner only and adding needs an Idempotency-Key', function () {
    app(ImportSchoolDirectory::class)->execute(sampleRegion(), 'Central');
    $id = DirectorySchool::query()->value('id');

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/school-directory/{$id}/customer")->assertUnprocessable()->assertJsonPath('code', 'idempotency_key_required');

    Sanctum::actingAs(User::factory()->create(['role' => 'school']));
    $this->getJson('/api/v1/school-directory')->assertForbidden();
    $this->withHeader('Idempotency-Key', 'x')->postJson("/api/v1/school-directory/{$id}/customer")->assertForbidden();
});

test('admin: search, add as customer and link an existing customer from the directory page', function () {
    app(ImportSchoolDirectory::class)->execute(sampleRegion(), 'Central');
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $stPeters = DirectorySchool::query()->where('source_ref', 'node/101')->first();
    $seaview = DirectorySchool::query()->where('source_ref', 'node/606')->first();

    Livewire::test(ListDirectorySchools::class)
        ->assertSee('OpenStreetMap contributors')
        ->searchTable('kasoa')
        ->assertCanSeeTableRecords([$stPeters])
        ->assertCanNotSeeTableRecords([$seaview])
        ->callAction(TestAction::make('addAsCustomer')->table($stPeters), data: ['contact_person' => 'Mrs Mensah', 'phone' => '+233 20 000 0001'])
        ->assertHasNoActionErrors()
        ->assertNotified("St. Peter's R/C Basic School added as CUS-0001");

    $customer = Customer::query()->sole();
    expect($stPeters->fresh()->customer_id)->toBe($customer->id)
        ->and($customer->contact_person)->toBe('Mrs Mensah');

    $other = Customer::factory()->create(['name' => 'Seaview SHS']);
    Livewire::test(ListDirectorySchools::class)
        ->callAction(TestAction::make('linkCustomer')->table($seaview), data: ['customer_id' => $other->id])
        ->assertNotified("Mankessim Methodist Junior Secondary School linked to {$other->code}");
    expect($seaview->fresh()->customer_id)->toBe($other->id);
});

test('only basic schools: SHS, colleges and universities are skipped, and ones loaded before are withdrawn', function () {
    $mapped = fn (string $name, array $tags = []) => OsmSchoolMapper::map(osmSchool('node', 9, ['amenity' => 'school', 'name' => $name, ...$tags]), 'Central', null);
    $basic = fn (string $name, array $tags = []) => OsmSchoolMapper::isBasic($mapped($name, $tags));

    expect($basic('Mfantsipim School'))->toBeTrue()                       // level unknown: kept
        ->and($basic('Bright Stars Creche'))->toBeTrue()
        ->and($basic('Winneba Presby Junior Secondary School'))->toBeTrue()
        ->and($mapped('Winneba Presby Junior Secondary School')['levels'])->toBe('jhs')
        ->and($basic('Akim Oda JHS and SHS'))->toBeTrue()                 // has a basic level
        ->and($basic('Ghanata Senior High School'))->toBeFalse()
        ->and($basic('Swedru Secondary School'))->toBeFalse()
        ->and($basic('St. Andrews Snr.High.School'))->toBeFalse()
        ->and($basic('The Morning Star International High School'))->toBeFalse()
        ->and($basic('MCS International High'))->toBeFalse()
        ->and($basic('Dzorwulu Junior High School'))->toBeTrue()
        ->and($basic('Kanda High Way School'))->toBeTrue()
        ->and($basic('Accra Technical Institute'))->toBeFalse()
        ->and($basic('Some School', ['isced:level' => '3']))->toBeFalse()
        ->and($basic('University of Cape Coast'))->toBeFalse()
        ->and($basic('Holy Child College of Education'))->toBeFalse()
        ->and($basic('Korle Bu Nursing Training College'))->toBeFalse()
        ->and($basic('St Mary Preparatory College'))->toBeTrue();         // "preparatory": a basic school

    // An SHS loaded by an earlier version of the import is withdrawn by the next one.
    DirectorySchool::query()->create(['source' => 'osm', 'source_ref' => 'node/505', 'name' => 'Seaview Senior High School', 'search_name' => 'seaview senior high school', 'region' => 'Central', 'levels' => 'shs']);
    expect(app(ImportSchoolDirectory::class)->execute(sampleRegion(), 'Central'))->toMatchArray(['created' => 4, 'withdrawn' => 1, 'listed' => 4])
        ->and(DirectorySchool::query()->listed()->where('levels', 'like', '%shs%')->count())->toBe(0);
});
