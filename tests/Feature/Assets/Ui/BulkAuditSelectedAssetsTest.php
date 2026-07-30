<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Covers the checked-rows bulk-audit flow (Selected Actions >
 * Bulk Audit on the assets index). Distinct from the existing
 * BulkAuditAssetsTest which covers /hardware/bulkaudit, the barcode-
 * scanner quickscan workflow at route `assets.bulkaudit`.
 *
 * Route pair under test: `hardware.bulk-audit.show` /
 * `hardware.bulk-audit.store`, controller BulkAssetsController.
 */
#[Group('auditing')]
class BulkAuditSelectedAssetsTest extends TestCase
{
    public function test_permission_required_to_view_form(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('hardware.bulk-audit.show'))
            ->assertForbidden();
    }

    public function test_auditor_can_view_form(): void
    {
        $this->actingAs(User::factory()->auditAssets()->create())
            ->get(route('hardware.bulk-audit.show'))
            ->assertOk()
            ->assertViewIs('hardware.bulk-audit');
    }

    public function test_bulk_actions_dispatcher_redirects_to_show_with_selected_assets_flashed(): void
    {
        // BulkAssetsController::edit gates on view permission before
        // dispatching to any specific action, so the auditor also
        // needs view to reach the audit branch.
        $auditor = User::factory()->viewAssets()->auditAssets()->create();
        $assets = Asset::factory()->count(3)->create();

        $this->actingAs($auditor)
            ->post(route('hardware.bulkedit.show'), [
                'bulk_actions' => 'audit',
                'ids' => $assets->pluck('id')->toArray(),
            ])
            ->assertRedirect(route('hardware.bulk-audit.show'));
    }

    public function test_permission_required_to_submit(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('hardware.bulk-audit.store'), [
                'selected_assets' => [1, 2, 3],
            ])
            ->assertForbidden();
    }

    public function test_submitting_without_selected_assets_bounces_back(): void
    {
        $this->actingAs(User::factory()->auditAssets()->create())
            ->post(route('hardware.bulk-audit.store'), [])
            ->assertRedirect(route('hardware.bulk-audit.show'))
            ->assertSessionHas('error');
    }

    public function test_bulk_audit_writes_one_audit_actionlog_per_asset(): void
    {
        $assets = Asset::factory()->count(3)->create();

        $this->actingAs(User::factory()->auditAssets()->create())
            ->post(route('hardware.bulk-audit.store'), [
                'selected_assets' => $assets->pluck('id')->toArray(),
                'note' => 'bulk audit test',
            ])
            ->assertRedirect(route('hardware.index'))
            ->assertSessionHas('success');

        foreach ($assets as $asset) {
            $this->assertDatabaseHas('action_logs', [
                'action_type' => 'audit',
                'item_type' => Asset::class,
                'item_id' => $asset->id,
                'note' => 'bulk audit test',
            ]);
        }
    }

    public function test_bulk_audit_updates_next_audit_date_when_provided(): void
    {
        $assets = Asset::factory()->count(2)->create();

        $this->actingAs(User::factory()->auditAssets()->create())
            ->post(route('hardware.bulk-audit.store'), [
                'selected_assets' => $assets->pluck('id')->toArray(),
                'next_audit_date' => '2099-01-15',
            ])
            ->assertRedirect(route('hardware.index'));

        // next_audit_date has an Attribute accessor that returns a
        // pre-formatted date string, not a Carbon instance, so we
        // check the raw column value instead.
        foreach ($assets as $asset) {
            $this->assertSame('2099-01-15', $asset->fresh()->getRawOriginal('next_audit_date'));
        }
    }

    public function test_bulk_audit_updates_asset_location_when_update_location_opt_in_is_checked(): void
    {
        $location = Location::factory()->create();
        $assets = Asset::factory()->count(2)->create();

        $this->actingAs(User::factory()->auditAssets()->create())
            ->post(route('hardware.bulk-audit.store'), [
                'selected_assets' => $assets->pluck('id')->toArray(),
                'location_id' => $location->id,
                'update_location' => '1',
            ])
            ->assertRedirect(route('hardware.index'));

        foreach ($assets as $asset) {
            $this->assertSame($location->id, $asset->fresh()->location_id);
        }
    }

    public function test_bulk_audit_does_not_move_assets_when_update_location_is_unchecked(): void
    {
        // Provided location_id + no update_location flag should
        // record the audit's location on each log entry (as "where
        // the audit happened") without touching the asset's actual
        // location_id. Matches the API's update_location=1 gate.
        $originalLocation = Location::factory()->create();
        $auditLocation = Location::factory()->create();
        $assets = Asset::factory()->count(2)->create(['location_id' => $originalLocation->id]);

        $this->actingAs(User::factory()->auditAssets()->create())
            ->post(route('hardware.bulk-audit.store'), [
                'selected_assets' => $assets->pluck('id')->toArray(),
                'location_id' => $auditLocation->id,
                // update_location intentionally omitted (unchecked)
            ])
            ->assertRedirect(route('hardware.index'));

        foreach ($assets as $asset) {
            $fresh = $asset->fresh();
            $this->assertSame(
                $originalLocation->id,
                $fresh->location_id,
                "Asset {$asset->id} location must not change when update_location is unchecked.",
            );

            // But the audit log DOES record the submitted location.
            $log = Actionlog::where('action_type', 'audit')
                ->where('item_type', Asset::class)
                ->where('item_id', $asset->id)
                ->first();
            $this->assertNotNull($log);
            $this->assertSame($auditLocation->id, (int) $log->location_id);
        }
    }

    public function test_form_prefills_next_audit_date_from_audit_interval(): void
    {
        $this->settings->setAuditInterval(6);

        $this->actingAs(User::factory()->auditAssets()->create())
            ->get(route('hardware.bulk-audit.show'))
            ->assertOk()
            ->assertViewHas('next_audit_date', \Carbon\Carbon::now()->addMonths(6)->toDateString());
    }

    public function test_form_leaves_next_audit_date_blank_when_no_audit_interval_configured(): void
    {
        // With no audit_interval set, "today + 0 months" would prefill
        // as today, which reads as "audit again right now" and is
        // useless. The form should start empty and let the user pick
        // a date (or leave blank to skip updating next_audit_date).
        $this->settings->setAuditInterval(null);

        $this->actingAs(User::factory()->auditAssets()->create())
            ->get(route('hardware.bulk-audit.show'))
            ->assertOk()
            ->assertViewHas('next_audit_date', null);
    }

    public function test_bulk_audit_attaches_uploaded_image_to_every_row(): void
    {
        // Matches the API's bulkAudit behavior: one uploaded image is
        // stamped onto every asset's audit log entry, with the stored
        // filename scoped by asset id (audit-{id}-...).
        Storage::fake();
        $assets = Asset::factory()->count(2)->create();

        $this->actingAs(User::factory()->auditAssets()->create())
            ->post(route('hardware.bulk-audit.store'), [
                'selected_assets' => $assets->pluck('id')->toArray(),
                'image' => UploadedFile::fake()->image('audit.png'),
            ])
            ->assertRedirect(route('hardware.index'))
            ->assertSessionHas('success');

        foreach ($assets as $asset) {
            $log = Actionlog::where('action_type', 'audit')
                ->where('item_type', Asset::class)
                ->where('item_id', $asset->id)
                ->first();

            $this->assertNotNull($log, "Audit log missing for asset {$asset->id}");
            $this->assertNotEmpty($log->filename, "Audit log filename missing for asset {$asset->id}");
            $this->assertStringStartsWith('audit-'.$asset->id, $log->filename);
        }
    }

    public function test_bulk_audit_rejects_unresolvable_location_before_touching_any_asset(): void
    {
        $assets = Asset::factory()->count(2)->create();
        $originalLocations = $assets->pluck('location_id', 'id');

        $this->actingAs(User::factory()->auditAssets()->create())
            ->post(route('hardware.bulk-audit.store'), [
                'selected_assets' => $assets->pluck('id')->toArray(),
                'location_id' => 9999999, // does not exist
            ])
            ->assertRedirect(route('hardware.bulk-audit.show'))
            ->assertSessionHas('error');

        // No audit rows written, no location mutations applied.
        $this->assertSame(0, Actionlog::where('action_type', 'audit')->count());
        foreach ($assets as $asset) {
            $this->assertSame($originalLocations[$asset->id], $asset->fresh()->location_id);
        }
    }
}
