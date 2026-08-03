<?php

namespace Tests\Feature\Importing\Api;

use App\Models\Actionlog as ActivityLog;
use App\Models\Consumable;
use App\Models\Import;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TestsPermissionsRequirement;
use Tests\Support\Importing\CleansUpImportFiles;
use Tests\Support\Importing\ConsumablesImportFileBuilder as ImportFileBuilder;

class ImportConsumablesTest extends ImportDataTestCase implements TestsPermissionsRequirement
{
    use CleansUpImportFiles;
    use WithFaker;

    protected function importFileResponse(array $parameters = []): TestResponse
    {
        if (! array_key_exists('import-type', $parameters)) {
            $parameters['import-type'] = 'consumable';
        }

        return parent::importFileResponse($parameters);
    }

    #[Test]
    public function test_requires_permission()
    {
        $this->actingAsForApi(User::factory()->create());

        $this->importFileResponse(['import' => 44])->assertForbidden();
    }

    #[Test]
    public function user_with_import_assets_permission_can_import_consumables(): void
    {
        $this->actingAsForApi(User::factory()->canImport()->create());

        $import = Import::factory()->consumable()->create();

        $this->importFileResponse(['import' => $import->id])->assertOk();
    }

    #[Test]
    public function import_consumables(): void
    {
        Notification::fake();

        $importFileBuilder = ImportFileBuilder::new();
        $row = $importFileBuilder->firstRow();
        $import = Import::factory()->consumable()->create(['file_path' => $importFileBuilder->saveToImportsDirectory()]);

        $this->actingAsForApi(User::factory()->superuser()->create());
        $this->importFileResponse(['import' => $import->id])
            ->assertOk()
            ->assertExactJson([
                'payload' => ['tally' => ['created' => 1, 'updated' => 0, 'skipped' => 0, 'errored' => 0]],
                'status' => 'success',
                'messages' => ['redirect_url' => route('consumables.index')],
            ]);

        $newConsumable = Consumable::query()
            ->with(['location', 'category', 'company'])
            ->where('name', $row['itemName'])
            ->sole();

        $activityLog = ActivityLog::query()
            ->where('item_type', Consumable::class)
            ->where('item_id', $newConsumable->id)
            ->sole();

        $this->assertEquals('create', $activityLog->action_type);
        $this->assertEquals('importer', $activityLog->action_source);
        $this->assertEquals($newConsumable->company->id, $activityLog->company_id);

        $this->assertEquals($row['itemName'], $newConsumable->name);
        $this->assertEquals($row['category'], $newConsumable->category->name);
        $this->assertEquals($row['location'], $newConsumable->location->name);
        $this->assertEquals($row['companyName'], $newConsumable->company->name);
        $this->assertNotNull($newConsumable->supplier_id);
        $this->assertFalse($newConsumable->requestable);
        $this->assertNull($newConsumable->image);
        // order_number moved off the parent Consumable column to the
        // Orders / OrderItems polymorphic pair. Verify the importer's
        // recordOrderForImportedRow helper wrote a matching Order and
        // linked it to the new consumable via an OrderItem.
        $orderItem = $newConsumable->orderItems()->firstOrFail();
        $this->assertEquals($row['orderNumber'], $orderItem->order->order_number);
        $this->assertEquals($row['purchaseDate'], $newConsumable->purchase_date->toDateString());
        $this->assertEquals($row['purchaseCost'], $newConsumable->purchase_cost);
        $this->assertNull($newConsumable->min_amt);
        $this->assertEquals('', $newConsumable->model_number);
        $this->assertNull($newConsumable->item_number);
        $this->assertNull($newConsumable->manufacturer_id);
        $this->assertNull($newConsumable->notes);
    }

    #[Test]
    public function will_ignore_unknown_columns_when_file_contains_unknown_columns(): void
    {
        $row = ImportFileBuilder::new()->definition();
        $row['unknownColumnInCsvFile'] = 'foo';

        $importFileBuilder = new ImportFileBuilder([$row]);

        $this->actingAsForApi(User::factory()->superuser()->create());

        $import = Import::factory()->consumable()->create(['file_path' => $importFileBuilder->saveToImportsDirectory()]);

        $this->importFileResponse(['import' => $import->id])->assertOk();
    }

    #[Test]
    public function will_not_create_new_consumable_when_consumable_name_already_exist(): void
    {
        $consumable = Consumable::factory()->create(['name' => Str::random()]);
        $importFileBuilder = ImportFileBuilder::new(['itemName' => $consumable->name]);
        $import = Import::factory()->consumable()->create(['file_path' => $importFileBuilder->saveToImportsDirectory()]);

        $this->actingAsForApi(User::factory()->superuser()->create());
        $this->importFileResponse(['import' => $import->id])->assertOk();

        $probablyNewConsumables = Consumable::query()
            ->where('name', $consumable->name)
            ->get();

        $this->assertCount(1, $probablyNewConsumables);
        $this->assertEquals($consumable->id, $probablyNewConsumables->sole()->id);
    }

    #[Test]
    public function will_not_create_new_company_when_company_exists(): void
    {
        $importFileBuilder = ImportFileBuilder::times(4)->replace(['companyName' => Str::random()]);
        $import = Import::factory()->consumable()->create(['file_path' => $importFileBuilder->saveToImportsDirectory()]);

        $this->actingAsForApi(User::factory()->superuser()->create());
        $this->importFileResponse(['import' => $import->id])->assertOk();

        $newConsumables = Consumable::query()
            ->whereIn('name', $importFileBuilder->pluck('itemName'))
            ->get(['company_id']);

        $this->assertCount(1, $newConsumables->pluck('company_id')->unique()->all());
    }

    #[Test]
    public function will_not_create_new_location_when_location_exists(): void
    {
        $importFileBuilder = ImportFileBuilder::times(4)->replace(['location' => Str::random()]);
        $import = Import::factory()->consumable()->create(['file_path' => $importFileBuilder->saveToImportsDirectory()]);

        $this->actingAsForApi(User::factory()->superuser()->create());
        $this->importFileResponse(['import' => $import->id])->assertOk();

        $newConsumables = Consumable::query()
            ->whereIn('name', $importFileBuilder->pluck('itemName'))
            ->get(['location_id']);

        $this->assertCount(1, $newConsumables->pluck('location_id')->unique()->all());
    }

    #[Test]
    public function will_not_create_new_category_when_category_exists(): void
    {
        $importFileBuilder = ImportFileBuilder::times(4)->replace(['category' => Str::random()]);
        $import = Import::factory()->consumable()->create(['file_path' => $importFileBuilder->saveToImportsDirectory()]);

        $this->actingAsForApi(User::factory()->superuser()->create());
        $this->importFileResponse(['import' => $import->id])->assertOk();

        $newConsumables = Consumable::query()
            ->whereIn('name', $importFileBuilder->pluck('itemName'))
            ->get(['category_id']);

        $this->assertCount(1, $newConsumables->pluck('category_id')->unique()->all());
    }

    #[Test]
    public function when_required_columns_are_missing_in_import_file(): void
    {
        $importFileBuilder = ImportFileBuilder::new(['category' => ''])->forget(['quantity', 'name']);

        $row = $importFileBuilder->firstRow();
        $import = Import::factory()->consumable()->create(['file_path' => $importFileBuilder->saveToImportsDirectory()]);

        $this->actingAsForApi(User::factory()->superuser()->create());

        $this->importFileResponse(['import' => $import->id])
            ->assertInternalServerError()
            ->assertExactJson([
                'status' => 'import-errors',
                'payload' => ['tally' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 1]],
                'messages' => [
                    $row['itemName'] => [
                        'Consumable' => [
                            'category_id' => ['The category id field is required.'],
                            'qty' => ['The qty field is required.'],
                        ],
                    ],
                ],
            ]);

        $newConsumables = Consumable::query()
            ->whereIn('name', $importFileBuilder->pluck('itemName'))
            ->get(['id']);

        $this->assertCount(0, $newConsumables);
    }

    #[Test]
    public function update_consumable_from_import(): void
    {
        $consumable = Consumable::factory()->create(['name' => Str::random()]);
        $importFileBuilder = ImportFileBuilder::new(['itemName' => $consumable->name]);

        $row = $importFileBuilder->firstRow();
        $import = Import::factory()->consumable()->create(['file_path' => $importFileBuilder->saveToImportsDirectory()]);

        $this->actingAsForApi(User::factory()->superuser()->create());
        $this->importFileResponse(['import' => $import->id, 'import-update' => true])->assertOk();

        $updatedConsumable = Consumable::query()
            ->with(['location', 'category', 'company'])
            ->where('name', $importFileBuilder->firstRow()['itemName'])
            ->sole();

        $this->assertEquals($row['itemName'], $updatedConsumable->name);
        $this->assertEquals($row['category'], $updatedConsumable->category->name);
        $this->assertEquals($row['location'], $updatedConsumable->location->name);
        $this->assertEquals($row['companyName'], $updatedConsumable->company->name);
        // order_number does NOT persist on the parent through an update
        // (see update_accessory_from_import for the same rationale;
        // importer_qty_change_creates_quantity_adjust_log covers the log).
        $this->assertEquals($row['purchaseDate'], $updatedConsumable->purchase_date->toDateString());
        $this->assertEquals($row['purchaseCost'], $updatedConsumable->purchase_cost);

        $this->assertEquals($row['supplier'], $updatedConsumable->supplier->name);
        $this->assertEquals($consumable->requestable, $updatedConsumable->requestable);
        $this->assertEquals($consumable->min_amt, $updatedConsumable->min_amt);
        $this->assertEquals($consumable->model_number, $updatedConsumable->model_number);
        $this->assertEquals($consumable->item_number, $updatedConsumable->item_number);
        $this->assertEquals($consumable->manufacturer_id, $updatedConsumable->manufacturer_id);
        $this->assertEquals($consumable->notes, $updatedConsumable->notes);
        $this->assertEquals($consumable->item_number, $updatedConsumable->item_number);
    }

    #[Test]
    public function importer_qty_change_creates_quantity_adjust_log(): void
    {
        // Update path routes any qty delta through adjustQuantity so the
        // change becomes a QuantityAdjust action_log entry rather than a
        // silent overwrite. Same contract the API update path uses.
        $consumable = Consumable::factory()->create(['name' => Str::random(), 'qty' => 5]);
        $importFileBuilder = ImportFileBuilder::new([
            'itemName' => $consumable->name,
            'quantity' => 12,
        ]);
        $import = Import::factory()->consumable()->create([
            'file_path' => $importFileBuilder->saveToImportsDirectory(),
        ]);

        $this->actingAsForApi(User::factory()->superuser()->create());
        $this->importFileResponse(['import' => $import->id, 'import-update' => true])->assertOk();

        $this->assertSame(12, (int) $consumable->fresh()->qty);

        $log = ActivityLog::where('item_type', Consumable::class)
            ->where('item_id', $consumable->id)
            ->where('action_type', \App\Enums\ActionType::QuantityAdjust->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(7, (int) $log->quantity);
        $this->assertStringContainsString('Import: qty updated from 5 to 12', (string) $log->note);
    }

    #[Test]
    public function update_mode_clears_field_when_csv_column_is_present_but_empty(): void
    {
        $this->actingAsForApi(User::factory()->superuser()->create());

        // purchase_date is the sole proxy for the "empty CSV cell clears
        // the DB column" behavior here. order_number is not exercised
        // because Consumable / Accessory / Component all hide the
        // parent-level order_number behind an accessor that returns null
        // (see Consumable::getOrderNumberAttribute) — reads via
        // $consumable->order_number would be null regardless of the DB
        // state and would give a false green for this test.
        $consumable = Consumable::factory()->create([
            'purchase_date' => '2022-01-01',
        ])->refresh();

        $this->assertNotNull($consumable->purchase_date);

        $row = ImportFileBuilder::new()->definition();
        $row['itemName'] = $consumable->name;
        $row['purchaseDate'] = '';

        $importFileBuilder = new ImportFileBuilder([$row]);
        $import = Import::factory()->consumable()->create([
            'file_path' => $importFileBuilder->saveToImportsDirectory(),
        ]);

        $this->importFileResponse([
            'import' => $import->id,
            'import-update' => true,
        ])->assertOk();

        $consumable->refresh();
        $this->assertNull($consumable->purchase_date);
    }

    #[Test]
    public function update_mode_preserves_fields_when_csv_column_is_absent(): void
    {
        $this->actingAsForApi(User::factory()->superuser()->create());

        $consumable = Consumable::factory()->create([
            'purchase_date' => '2022-01-01',
        ])->refresh();

        $originalPurchaseDate = $consumable->purchase_date?->toDateString();

        // Import a CSV that only has the identity field (name) plus quantity
        // (required by Consumable validation). All other Consumable fields
        // are absent from the CSV, so their DB values must be preserved.
        // order_number moved off the parent Consumable column to the Orders
        // data model, so we can't use it as a preservation proxy here;
        // purchase_date is the remaining stand-in.
        $partialFile = new ImportFileBuilder([[
            'itemName' => $consumable->name,
            'quantity' => 42,
        ]]);
        $partialImport = Import::factory()->consumable()->create([
            'file_path' => $partialFile->saveToImportsDirectory(),
        ]);

        $this->importFileResponse([
            'import' => $partialImport->id,
            'import-update' => true,
        ])->assertOk();

        $consumable->refresh();
        $this->assertEquals(42, $consumable->qty);
        $this->assertEquals($originalPurchaseDate, $consumable->purchase_date?->toDateString());
    }

    #[Test]
    public function update_mode_logs_consumable_update_in_actionlog(): void
    {
        $this->actingAsForApi(User::factory()->superuser()->create());

        $initialFile = ImportFileBuilder::new();
        $initialRow = $initialFile->firstRow();

        $initialImport = Import::factory()->consumable()->create([
            'file_path' => $initialFile->saveToImportsDirectory(),
        ]);

        $this->importFileResponse(['import' => $initialImport->id])->assertOk();

        $consumable = Consumable::query()->where('name', $initialRow['itemName'])->sole();

        // Change `purchaseCost` (a plain fillable column that IS in the
        // ConsumablesImportFileBuilder shape). orderNumber is intentionally
        // NOT the trigger because ItemImporter::applyUpdateWithQtyAdjust
        // strips order_number from the update payload, so a non-qty /
        // non-order-number diff is what proves the update-log path fires.
        $updatedRow = array_merge($initialRow, [
            'purchaseCost' => ((int) $initialRow['purchaseCost']) + 1,
        ]);

        $updateFile = new ImportFileBuilder([$updatedRow]);
        $updateImport = Import::factory()->consumable()->create([
            'file_path' => $updateFile->saveToImportsDirectory(),
        ]);

        $this->importFileResponse([
            'import' => $updateImport->id,
            'import-update' => true,
        ])->assertOk();

        $consumable->refresh();
        $this->assertEquals($updatedRow['purchaseCost'], $consumable->purchase_cost);

        $updateLog = ActivityLog::query()
            ->where('item_type', Consumable::class)
            ->where('item_id', $consumable->id)
            ->where('action_type', 'update')
            ->latest('id')
            ->first();

        $this->assertNotNull($updateLog, 'Expected an update action log entry after consumable importer update mode.');
    }

    #[Test]
    public function custom_column_mapping(): void
    {
        $faker = ImportFileBuilder::new()->definition();
        $row = [
            'category' => $faker['supplier'],
            'companyName' => $faker['quantity'],
            'itemName' => $faker['purchaseDate'],
            'location' => $faker['purchaseCost'],
            'orderNumber' => $faker['orderNumber'],
            'purchaseCost' => $faker['location'],
            'purchaseDate' => $faker['companyName'],
            'quantity' => $faker['itemName'],
            'supplier' => $faker['category'],
        ];

        $importFileBuilder = new ImportFileBuilder([$row]);

        $import = Import::factory()->consumable()->create(['file_path' => $importFileBuilder->saveToImportsDirectory()]);

        $this->actingAsForApi(User::factory()->superuser()->create());

        // This mapping is incorrect on purpose
        $this->importFileResponse([
            'import' => $import->id,
            'column-mappings' => [
                'Category' => 'supplier',
                'Company' => 'quantity',
                'item Name' => 'purchase_date',
                'Location' => 'purchase_cost',
                'Order Number' => 'order_number',
                'Purchase Cost' => 'location',
                'Purchase Date' => 'company',
                'Quantity' => 'item_name',
                'Supplier' => 'category',
            ],
        ])->assertOk();

        $newConsumable = Consumable::query()
            ->with(['location', 'category', 'company'])
            ->where('name', $importFileBuilder->firstRow()['quantity'])
            ->sole();

        $this->assertEquals($row['supplier'], $newConsumable->category->name);
        $this->assertEquals($row['purchaseCost'], $newConsumable->location->name);
        $this->assertEquals($row['purchaseDate'], $newConsumable->company->name);
        $this->assertEquals($row['companyName'], $newConsumable->qty);
        $this->assertEquals($row['quantity'], $newConsumable->name);
        $this->assertNotNull($newConsumable->supplier_id);
        $this->assertFalse($newConsumable->requestable);
        $this->assertNull($newConsumable->image);
        // See the import_consumables test above for why order_number now
        // lives on Orders / OrderItems rather than the parent column.
        $orderItem = $newConsumable->orderItems()->firstOrFail();
        $this->assertEquals($row['orderNumber'], $orderItem->order->order_number);
        $this->assertEquals($row['itemName'], $newConsumable->purchase_date->toDateString());
        $this->assertEquals($row['location'], $newConsumable->purchase_cost);
        $this->assertNull($newConsumable->min_amt);
        $this->assertEquals('', $newConsumable->model_number);
        $this->assertNull($newConsumable->item_number);
        $this->assertNull($newConsumable->manufacturer_id);
        $this->assertNull($newConsumable->notes);
    }

    #[Test]
    public function consumable_import_checks_out_to_user_when_username_matches(): void
    {
        $actor = User::factory()->superuser()->create();
        $target = User::factory()->create(['username' => 'consumabletarget']);

        $csv = "Item Name,Category,Quantity,Company,Checkout Type,Username\n"
            .'CSV-Checked-Consumable,Toner,10,CSVCo,user,'.$target->username."\n";
        $filename = 'consumable-checkout-'.uniqid().'.csv';
        file_put_contents(config('app.private_uploads').'/imports/'.$filename, $csv);

        try {
            $import = Import::factory()->consumable()->create(['file_path' => $filename]);

            $this->actingAsForApi($actor);
            $this->importFileResponse(['import' => $import->id])->assertOk();

            $consumable = Consumable::query()->where('name', 'CSV-Checked-Consumable')->sole();
            $this->assertEquals(10, $consumable->qty);

            $this->assertDatabaseHas('consumables_users', [
                'consumable_id' => $consumable->id,
                'assigned_to' => $target->id,
            ]);
        } finally {
            @unlink(config('app.private_uploads').'/imports/'.$filename);
        }
    }

    #[Test]
    public function consumable_import_ignores_location_checkout_target(): void
    {
        // consumables_users only supports user targets - a location
        // target in the CSV should be silently skipped rather than
        // producing an invalid pivot row.
        $actor = User::factory()->superuser()->create();

        $csv = "Item Name,Category,Quantity,Company,Checkout Type,Checkout Location\n"
            ."CSV-Loc-Consumable,Toner,4,CSVCo,location,HQ\n";
        $filename = 'consumable-loc-'.uniqid().'.csv';
        file_put_contents(config('app.private_uploads').'/imports/'.$filename, $csv);

        try {
            $import = Import::factory()->consumable()->create(['file_path' => $filename]);

            $this->actingAsForApi($actor);
            $this->importFileResponse(['import' => $import->id])->assertOk();

            $consumable = Consumable::query()->where('name', 'CSV-Loc-Consumable')->sole();
            $this->assertDatabaseMissing('consumables_users', [
                'consumable_id' => $consumable->id,
            ]);
        } finally {
            @unlink(config('app.private_uploads').'/imports/'.$filename);
        }
    }
}
