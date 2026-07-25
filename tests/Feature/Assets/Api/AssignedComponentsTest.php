<?php

namespace Tests\Feature\Assets\Api;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Component;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class AssignedComponentsTest extends TestCase
{
    public function test_requires_permission()
    {
        $this->actingAsForApi(User::factory()->create())
            ->getJson(route('api.assets.assigned_components', Asset::factory()->create()))
            ->assertForbidden();
    }

    public function test_adheres_to_company_scoping()
    {
        $this->settings->enableMultipleFullCompanySupport();

        [$companyA, $companyB] = Company::factory()->count(2)->create();

        $asset = Asset::factory()->for($companyA)->create();

        $user = User::factory()->forCompany($companyB)->viewAssets()->create();

        $this->actingAsForApi($user)
            ->getJson(route('api.assets.assigned_components', $asset))
            ->assertOk()
            ->assertStatusMessageIs('error')
            ->assertMessagesAre('Asset not found');
    }

    public function test_can_get_components_assigned_to_specific_asset()
    {
        $unassociatedComponent = Component::factory()->create();

        $asset = Asset::factory()->hasComponents(2)->create();

        $componentsAssignedToAsset = $asset->components;

        $response = $this->actingAsForApi(User::factory()->viewAssets()->create())
            ->getJson(route('api.assets.assigned_components', $asset))
            ->assertOk()
            // ->assertResponseContainsInRows($componentsAssignedToAsset)
            // ->assertResponseDoesNotContainInRows($unassociatedComponent)
            ->assertJson(function (AssertableJson $json) {
                $json->where('total', 2)
                    ->count('rows', 2)
                    ->etc();
            });
    }

    /**
     * Security regression pin. Before the fix in
     * AssetsTransformer::transformCheckedoutComponents, a caller with
     * assets.view but not components.view could read the component's
     * name / assigned_qty / note through this endpoint (direct
     * GET /api/v1/components/{id} correctly returned 403). Now the row
     * collapses to just `{id, type: 'component'}` in the name block,
     * and qty / note are nulled, when the caller lacks components.view.
     */
    public function test_does_not_leak_component_details_when_caller_lacks_components_view(): void
    {
        $asset = Asset::factory()->create();
        $component = Component::factory()->create(['name' => 'Hidden SSD Component']);
        $component->assets()->attach($component->id, [
            'component_id' => $component->id,
            'assigned_qty' => 2,
            'created_by' => User::factory()->superuser()->create()->id,
            'asset_id' => $asset->id,
            'note' => 'sensitive component note',
        ]);

        // viewAssets only. No viewComponents.
        $actor = User::factory()->viewAssets()->create();

        $response = $this->actingAsForApi($actor)
            ->getJson(route('api.assets.assigned_components', $asset))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.name.id', $component->id)
            ->assertJsonPath('rows.0.name.type', 'component');

        $nameBlock = $response->json('rows.0.name');
        $this->assertArrayNotHasKey('name', $nameBlock, 'Component name must not leak to a caller without components.view');

        $this->assertNull($response->json('rows.0.assigned_qty'), 'assigned_qty must not leak');
        $this->assertNull($response->json('rows.0.note'), 'note must not leak');
    }

    /**
     * Positive-path sanity: a caller who DOES have components.view still
     * gets the full component details. Guards against the info-disclosure
     * guard accidentally stripping data from authorized callers.
     */
    public function test_returns_full_component_details_when_caller_has_components_view(): void
    {
        $asset = Asset::factory()->create();
        $component = Component::factory()->create(['name' => 'Visible SSD']);
        $component->assets()->attach($component->id, [
            'component_id' => $component->id,
            'assigned_qty' => 2,
            'created_by' => User::factory()->superuser()->create()->id,
            'asset_id' => $asset->id,
            'note' => 'component note',
        ]);

        $actor = User::factory()->viewAssets()->viewComponents()->create();

        $this->actingAsForApi($actor)
            ->getJson(route('api.assets.assigned_components', $asset))
            ->assertOk()
            ->assertJsonPath('rows.0.name.id', $component->id)
            ->assertJsonPath('rows.0.name.name', 'Visible SSD')
            ->assertJsonPath('rows.0.assigned_qty', 2)
            ->assertJsonPath('rows.0.note', 'component note');
    }
}
