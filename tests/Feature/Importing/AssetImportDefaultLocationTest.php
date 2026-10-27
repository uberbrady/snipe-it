<?php

namespace Tests\Feature\Importing;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Company;
use App\Models\Import;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetImportDefaultLocationTest extends TestCase
{
    /** @var array<int, string> */
    private array $uploadedTempPaths = [];

    protected function tearDown(): void
    {
        // tempnam() writes into /tmp and Laravel's test harness doesn't
        // clean them up by itself, so we track + unlink per-test to keep
        // CI runners' /tmp from accreting a CSV per import test. Also
        // clean out the imports storage directory for files the import
        // controller moved into place during the test.
        foreach ($this->uploadedTempPaths as $path) {
            @unlink($path);
        }
        foreach (Import::query()->get() as $import) {
            @unlink(config('app.private_uploads').'/imports/'.$import->file_path);
        }
        parent::tearDown();
    }

    private function createFakeUploadedFile(string $filename, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);
        $this->uploadedTempPaths[] = $path;

        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    private function runAssetImport(string $csv, array $mappings): void
    {
        Category::factory()->create(['category_type' => 'asset', 'name' => 'Default Loc Test Category']);
        Statuslabel::factory()->create(['name' => 'Default Loc Test Status']);
        Company::factory()->create();
        AssetModel::factory()
            ->for(Category::where('name', 'Default Loc Test Category')->first(), 'category')
            ->create(['name' => 'Default Loc Test Model']);

        $importer = User::factory()->canImport()->create();

        $this->actingAsForApi($importer)
            ->postJson(route('api.imports.store'), [
                'files' => [
                    $this->createFakeUploadedFile('default-loc.csv', $csv),
                ],
            ])
            ->assertSuccessful();

        $import = Import::latest()->first();

        $this->actingAsForApi($importer)
            ->postJson(route('api.imports.importFile', $import->id), [
                'import-type' => 'asset',
                'column-mappings' => $mappings,
            ])
            ->assertSuccessful();
    }

    #[Test]
    public function default_location_only_writes_rtd_location_without_touching_current_location(): void
    {
        $newDefault = Location::factory()->create(['name' => 'New Default Loc']);

        $csv = "asset tag,item name,category,status,model name,default location\n";
        $csv .= "DL-001,Default-only Asset,Default Loc Test Category,Default Loc Test Status,Default Loc Test Model,{$newDefault->name}\n";

        $this->runAssetImport($csv, [
            'asset tag' => 'asset_tag',
            'item name' => 'item_name',
            'category' => 'category',
            'status' => 'status',
            'model name' => 'asset_model',
            'default location' => 'default_location',
        ]);

        $imported = Asset::where('asset_tag', 'DL-001')->firstOrFail();
        $this->assertSame($newDefault->id, $imported->rtd_location_id);
        $this->assertNull($imported->location_id);
    }

    #[Test]
    public function location_only_mirrors_to_both_current_and_default_location_for_back_compat(): void
    {
        $target = Location::factory()->create(['name' => 'Mirror Loc']);

        $csv = "asset tag,item name,category,status,model name,location\n";
        $csv .= "DL-002,Mirror Asset,Default Loc Test Category,Default Loc Test Status,Default Loc Test Model,{$target->name}\n";

        $this->runAssetImport($csv, [
            'asset tag' => 'asset_tag',
            'item name' => 'item_name',
            'category' => 'category',
            'status' => 'status',
            'model name' => 'asset_model',
            'location' => 'location',
        ]);

        $imported = Asset::where('asset_tag', 'DL-002')->firstOrFail();
        $this->assertSame($target->id, $imported->location_id);
        $this->assertSame($target->id, $imported->rtd_location_id);
    }

    #[Test]
    public function explicit_default_location_wins_over_mirror_from_location_when_both_present(): void
    {
        $currentLoc = Location::factory()->create(['name' => 'Current Office']);
        $defaultLoc = Location::factory()->create(['name' => 'Warehouse HQ']);

        $csv = "asset tag,item name,category,status,model name,location,default location\n";
        $csv .= "DL-003,Both-cols Asset,Default Loc Test Category,Default Loc Test Status,Default Loc Test Model,{$currentLoc->name},{$defaultLoc->name}\n";

        $this->runAssetImport($csv, [
            'asset tag' => 'asset_tag',
            'item name' => 'item_name',
            'category' => 'category',
            'status' => 'status',
            'model name' => 'asset_model',
            'location' => 'location',
            'default location' => 'default_location',
        ]);

        $imported = Asset::where('asset_tag', 'DL-003')->firstOrFail();
        $this->assertSame($currentLoc->id, $imported->location_id);
        $this->assertSame($defaultLoc->id, $imported->rtd_location_id);
    }
}
