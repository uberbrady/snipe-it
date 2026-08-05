<?php

namespace Tests\Feature\Consumables\Ui;

use App\Models\Category;
use App\Models\Company;
use App\Models\Consumable;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

class UpdateConsumableTest extends TestCase
{
    public function test_requires_permission_to_see_edit_consumable_page()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('consumables.edit', Consumable::factory()->create()))
            ->assertForbidden();
    }

    public function test_does_not_show_edit_consumable_page_from_another_company()
    {
        $this->settings->enableMultipleFullCompanySupport();

        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $consumableForCompanyA = Consumable::factory()->for($companyA)->create();
        $userForCompanyB = User::factory()->editConsumables()->forCompany($companyB)->create();

        $this->actingAs($userForCompanyB)
            ->get(route('consumables.edit', $consumableForCompanyA))
            ->assertRedirect(route('consumables.index'));
    }

    public function test_edit_consumable_page_renders()
    {
        $this->actingAs(User::factory()->editConsumables()->create())
            ->get(route('consumables.edit', Consumable::factory()->create()))
            ->assertOk()
            ->assertViewIs('consumables.edit');
    }

    public function test_cannot_update_consumable_belonging_to_another_company()
    {
        $this->settings->enableMultipleFullCompanySupport();

        [$companyA, $companyB] = Company::factory()->count(2)->create();

        $consumableForCompanyA = Consumable::factory()->for($companyA)->create();
        $userForCompanyB = User::factory()->editConsumables()->forCompany($companyB)->create();

        $this->actingAs($userForCompanyB)
            ->put(route('consumables.update', $consumableForCompanyA), [
                //
            ])
            ->assertStatus(302);
    }

    public function test_can_update_consumable()
    {
        $consumable = Consumable::factory()->create(['purchase_cost' => 42.00]);
        $originalQty = (int) $consumable->qty;
        $originalPurchaseCost = $consumable->purchase_cost;
        $newSupplier = Supplier::factory()->create();

        // qty, order_number, and purchase_cost are all create-only on
        // the parent now. qty routes through adjust-quantity;
        // order_number and purchase_cost ride on OrderItems. The edit
        // form drops all three silently, so a POST body carrying them
        // shouldn't overwrite the corresponding parent columns.
        $editable = [
            'company_id' => Company::factory()->create()->id,
            'name' => 'My Consumable',
            'category_id' => Category::factory()->consumableInkCategory()->create()->id,
            'manufacturer_id' => Manufacturer::factory()->create()->id,
            'location_id' => Location::factory()->create()->id,
            'model_number' => '8765',
            'item_no' => '5678',
            'purchase_date' => '2024-12-05',
            'min_amt' => '7',
            'notes' => 'Some Notes',
        ];

        $this->actingAs(User::factory()->createConsumables()->editConsumables()->create())
            ->put(route('consumables.update', $consumable), $editable + [
                'redirect_option' => 'index',
                'category_type' => 'consumable',
                'order_number' => 'ignored-908',
                'qty' => '9999',
                'purchase_cost' => '89.45', // dropped — see comment above
                'supplier_id' => $newSupplier->id,
            ])
            ->assertRedirect(route('consumables.index'));

        $this->assertDatabaseHas('consumables', $editable + [
            'qty' => $originalQty,
            'purchase_cost' => $originalPurchaseCost,
            'supplier_id' => $newSupplier->id,
        ]);
    }
}
