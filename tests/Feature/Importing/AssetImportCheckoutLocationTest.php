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
use App\Notifications\WelcomeNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for the reporter case where a CSV with a single
 * "checked out to: Location" column mapped to checkout_location did not
 * actually check the asset out to the location. The prior code required
 * BOTH a checkout_class column (with value "Location") AND a
 * checkout_location column; missing the first silently fell through to
 * user lookup, produced no target, and skipped the checkout with no
 * error surfaced. Location intent is now inferred when checkout_location
 * has a value and no explicit checkout_class contradicts it.
 */
class AssetImportCheckoutLocationTest extends TestCase
{
    private function seedBaseline(): array
    {
        $category = Category::factory()->create(['category_type' => 'asset']);
        Location::factory()->create();
        $statusLabel = Statuslabel::factory()->create();
        Company::factory()->create();
        $assetModel = AssetModel::factory()->for($category, 'category')->create(['name' => 'Checkout Location Test Model']);

        return [$category, $statusLabel, $assetModel];
    }

    private function importCsv(string $csv, array $mappings, array $extraPayload = []): void
    {
        $importer = User::factory()->canImport()->create();

        $this->actingAsForApi($importer)
            ->postJson(route('api.imports.store'), [
                'files' => [
                    $this->createFakeUploadedFile('checkout-location.csv', $csv),
                ],
            ])
            ->assertSuccessful();

        $import = Import::latest()->first();

        $this->actingAsForApi($importer)
            ->postJson(route('api.imports.importFile', $import->id), array_merge([
                'import-type' => 'asset',
                'import-update' => true,
                'column-mappings' => $mappings,
            ], $extraPayload))
            ->assertSuccessful();
    }

    private function createFakeUploadedFile(string $filename, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    #[Test]
    public function checkout_location_column_alone_checks_asset_out_to_the_named_location(): void
    {
        [$category, $statusLabel, $assetModel] = $this->seedBaseline();
        $destination = Location::factory()->create(['name' => 'BIC_ALS Office_AD']);

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'CHKLOC-001', 'assigned_to' => null]);

        $csv = "asset tag,checked out to: Location\n";
        $csv .= "CHKLOC-001,{$destination->name}\n";

        $this->importCsv($csv, [
            'asset tag' => 'asset_tag',
            'checked out to: Location' => 'checkout_location',
        ]);

        $imported = Asset::where('asset_tag', 'CHKLOC-001')->firstOrFail();

        $this->assertSame(
            $destination->id,
            $imported->assigned_to,
            'checkout_location alone must be enough to route a checkout to the location.',
        );
        $this->assertSame(
            Location::class,
            $imported->assigned_type,
            'The checkout target type must be Location.',
        );
    }

    #[Test]
    public function checkout_class_location_plus_checkout_location_still_works(): void
    {
        // Backward-compat pin. Operators who already have the two-column
        // shape from earlier docs must not regress when the inference path
        // is added.
        [$category, $statusLabel, $assetModel] = $this->seedBaseline();
        $destination = Location::factory()->create(['name' => 'Legacy Two Column Room']);

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'CHKLOC-002', 'assigned_to' => null]);

        $csv = "asset tag,checkout type,checkout location\n";
        $csv .= "CHKLOC-002,Location,{$destination->name}\n";

        $this->importCsv($csv, [
            'asset tag' => 'asset_tag',
            'checkout type' => 'checkout_class',
            'checkout location' => 'checkout_location',
        ]);

        $imported = Asset::where('asset_tag', 'CHKLOC-002')->firstOrFail();

        $this->assertSame($destination->id, $imported->assigned_to);
        $this->assertSame(Location::class, $imported->assigned_type);
    }

    #[Test]
    public function checkout_asset_column_alone_checks_asset_out_to_the_named_parent_asset(): void
    {
        [$category, $statusLabel, $assetModel] = $this->seedBaseline();

        $parent = Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'PARENT-001']);

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'CHILD-001', 'assigned_to' => null]);

        $csv = "asset tag,checkout asset\n";
        $csv .= "CHILD-001,{$parent->asset_tag}\n";

        $this->importCsv($csv, [
            'asset tag' => 'asset_tag',
            'checkout asset' => 'checkout_asset',
        ]);

        $child = Asset::where('asset_tag', 'CHILD-001')->firstOrFail();

        $this->assertSame($parent->id, $child->assigned_to);
        $this->assertSame(Asset::class, $child->assigned_type);
    }

    #[Test]
    public function checkout_asset_with_unknown_tag_skips_checkout_and_does_not_assign(): void
    {
        // Assets are never auto-created as checkout targets; a typo in
        // the tag column produces a skipped checkout, not a phantom asset.
        [$category, $statusLabel, $assetModel] = $this->seedBaseline();

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'CHILD-002', 'assigned_to' => null]);

        $csv = "asset tag,checkout asset\n";
        $csv .= "CHILD-002,DOES-NOT-EXIST\n";

        $this->importCsv($csv, [
            'asset tag' => 'asset_tag',
            'checkout asset' => 'checkout_asset',
        ]);

        $child = Asset::where('asset_tag', 'CHILD-002')->firstOrFail();

        $this->assertNull($child->assigned_to, 'A checkout_asset value that does not match must not assign.');
    }

    #[Test]
    public function checkout_user_column_alone_checks_asset_out_to_the_matching_user(): void
    {
        [$category, $statusLabel, $assetModel] = $this->seedBaseline();
        $targetUser = User::factory()->create(['username' => 'checkout_user_target']);

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'CHKUSR-001', 'assigned_to' => null]);

        $csv = "asset tag,checkout user\n";
        $csv .= "CHKUSR-001,{$targetUser->username}\n";

        $this->importCsv($csv, [
            'asset tag' => 'asset_tag',
            'checkout user' => 'checkout_user',
        ]);

        $imported = Asset::where('asset_tag', 'CHKUSR-001')->firstOrFail();

        $this->assertSame($targetUser->id, $imported->assigned_to);
        $this->assertSame(User::class, $imported->assigned_type);
    }

    #[Test]
    public function checkout_user_creates_new_user_when_row_has_enough_identity_data(): void
    {
        // Preserves the existing "create user on the fly" behavior of
        // createOrFetchUser: if the row carries additional identity
        // columns (email, full_name, etc.) alongside a checkout_user
        // username that does not yet exist, the user is created and the
        // asset is checked out to them in a single import pass.
        [$category, $statusLabel, $assetModel] = $this->seedBaseline();

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'CHKUSR-002', 'assigned_to' => null]);

        $csv = "asset tag,checkout user,first name,last name,email\n";
        $csv .= "CHKUSR-002,newhire,New,Hire,new.hire@example.org\n";

        $this->importCsv($csv, [
            'asset tag' => 'asset_tag',
            'checkout user' => 'checkout_user',
            'first name' => 'first_name',
            'last name' => 'last_name',
            'email' => 'email',
        ]);

        $newUser = User::where('username', 'newhire')->firstOrFail();
        $imported = Asset::where('asset_tag', 'CHKUSR-002')->firstOrFail();

        $this->assertSame($newUser->id, $imported->assigned_to);
        $this->assertSame(User::class, $imported->assigned_type);
    }

    #[Test]
    public function send_welcome_email_fires_when_checkout_creates_a_new_user_and_the_flag_is_on(): void
    {
        Notification::fake();

        [$category, $statusLabel, $assetModel] = $this->seedBaseline();

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'WELCOME-001', 'assigned_to' => null]);

        $csv = "asset tag,checkout user,first name,last name,email\n";
        $csv .= "WELCOME-001,welcome_new,Welcome,New,welcome.new@example.org\n";

        $this->importCsv(
            $csv,
            [
                'asset tag' => 'asset_tag',
                'checkout user' => 'checkout_user',
                'first name' => 'first_name',
                'last name' => 'last_name',
                'email' => 'email',
            ],
            ['send-welcome' => true],
        );

        $newUser = User::where('username', 'welcome_new')->firstOrFail();
        Notification::assertSentTo($newUser, WelcomeNotification::class);
    }

    #[Test]
    public function send_welcome_email_does_not_fire_for_existing_users(): void
    {
        Notification::fake();

        [$category, $statusLabel, $assetModel] = $this->seedBaseline();
        $existing = User::factory()->create([
            'username' => 'existing_user',
            'email' => 'existing@example.org',
        ]);

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'WELCOME-002', 'assigned_to' => null]);

        $csv = "asset tag,checkout user\n";
        $csv .= "WELCOME-002,{$existing->username}\n";

        $this->importCsv(
            $csv,
            [
                'asset tag' => 'asset_tag',
                'checkout user' => 'checkout_user',
            ],
            ['send-welcome' => true],
        );

        Notification::assertNotSentTo($existing, WelcomeNotification::class);
    }

    #[Test]
    public function send_welcome_email_does_not_fire_when_the_flag_is_off_even_for_a_new_user(): void
    {
        Notification::fake();

        [$category, $statusLabel, $assetModel] = $this->seedBaseline();

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'WELCOME-003', 'assigned_to' => null]);

        $csv = "asset tag,checkout user,first name,last name,email\n";
        $csv .= "WELCOME-003,welcome_off,Welcome,Off,welcome.off@example.org\n";

        $this->importCsv(
            $csv,
            [
                'asset tag' => 'asset_tag',
                'checkout user' => 'checkout_user',
                'first name' => 'first_name',
                'last name' => 'last_name',
                'email' => 'email',
            ],
            // no 'send-welcome' key
        );

        $newUser = User::where('username', 'welcome_off')->firstOrFail();
        Notification::assertNotSentTo($newUser, WelcomeNotification::class);
    }

    #[Test]
    public function explicit_checkout_class_user_overrides_present_checkout_location(): void
    {
        // Disambiguation escape hatch: if the operator explicitly names
        // "user" as the checkout class AND the row has user-identity
        // fields, the user path wins even when checkout_location is also
        // mapped. This preserves the older opt-in-to-user semantic for
        // anyone using the two-column disambiguating shape.
        [$category, $statusLabel, $assetModel] = $this->seedBaseline();
        Location::factory()->create(['name' => 'Not Used']);
        $targetUser = User::factory()->create(['username' => 'checkout_target_user']);

        Asset::factory()
            ->for($assetModel, 'model')
            ->for($statusLabel, 'status')
            ->create(['asset_tag' => 'CHKLOC-003', 'assigned_to' => null]);

        $csv = "asset tag,checkout type,checkout location,username\n";
        $csv .= "CHKLOC-003,User,Not Used,{$targetUser->username}\n";

        $this->importCsv($csv, [
            'asset tag' => 'asset_tag',
            'checkout type' => 'checkout_class',
            'checkout location' => 'checkout_location',
            'username' => 'username',
        ]);

        $imported = Asset::where('asset_tag', 'CHKLOC-003')->firstOrFail();

        $this->assertSame(
            $targetUser->id,
            $imported->assigned_to,
            'Explicit checkout_class=user must route to the user even when checkout_location is present.',
        );
        $this->assertSame(User::class, $imported->assigned_type);
    }
}
