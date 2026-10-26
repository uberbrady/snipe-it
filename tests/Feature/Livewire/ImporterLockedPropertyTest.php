<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Importer;
use App\Models\Asset;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class ImporterLockedPropertyTest extends TestCase
{
    public function test_livewire_rejects_client_writes_to_required_field_model_map(): void
    {
        // Livewire's #[Locked] attribute throws
        // CannotUpdateLockedPropertyException on any update() path (which
        // is what the Livewire test helper's set() method goes through).
        // A pre-fix component without the attribute would silently accept
        // the write and expose the sink.
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs(User::factory()->canImport()->create())
            ->test(Importer::class)
            ->set('required_field_model_map', [
                'asset' => ['Pwn\\DoesNotExist', ['item_name' => 'name']],
            ]);
    }

    public function test_required_for_type_rejects_a_class_that_does_not_implement_importable(): void
    {
        // Even if a Livewire regression ever bypasses #[Locked],
        // the Importable marker-interface check in
        // requiredForType() must still refuse to dispatch into a class
        // that has not opted in.
        $component = new Importer;

        $component->required_field_model_map = [
            'asset' => [\Illuminate\Support\Carbon::class, ['item_name' => 'name']],
        ];

        $this->assertSame([], $component->requiredForType('asset'));
    }

    public function test_required_for_type_rejects_a_class_that_does_not_exist(): void
    {
        // Separate case: non-existent class names must not reach `new`.
        // The pre-fix behavior would throw Error: Class not found. Post-
        // fix, the method short-circuits to [] because class_exists() is
        // false.
        $component = new Importer;

        $component->required_field_model_map = [
            'asset' => ['Pwn\\DoesNotExist', ['item_name' => 'name']],
        ];

        $this->assertSame([], $component->requiredForType('asset'));
    }

    public function test_required_for_type_still_works_for_a_whitelisted_class(): void
    {
        // Positive-path: the pre-fix behavior for a legitimate type still
        // works. Location is in ALLOWED_IMPORT_MODEL_CLASSES, so the
        // method walks Location::getRules() and returns the "name"
        // importer key because Location::$rules marks name as required.
        // (Asset is in the allowlist too but Asset::$rules["name"] is
        // nullable, so it would not surface in the required list.)
        $component = new Importer;

        $required = $component->requiredForType('location');

        $this->assertContains('name', $required);
    }

    public function test_required_for_type_rejects_unknown_types_without_dispatch(): void
    {
        // Unknown $type keys fall through to the empty-array path before
        // the allowlist check, so an attacker who can only influence the
        // scalar $type (not the map) also gets a safe no-op.
        $component = new Importer;

        $this->assertSame([], $component->requiredForType('nonexistent-type'));
        $this->assertSame([], $component->requiredForType(''));
        $this->assertSame([], $component->requiredForType(null));
    }

    public function test_asset_history_short_circuit_bypasses_the_dispatch_entirely(): void
    {
        // assetHistory has no backing model, so requiredForType returns
        // its hardcoded required-column list before the class-dispatch
        // branch runs. Confirms the existing short-circuit is intact.
        $component = new Importer;

        $this->assertSame(
            ['asset_tag', 'full_name', 'checkout_date'],
            $component->requiredForType('assetHistory')
        );
    }

    public function test_every_class_in_the_map_implements_importable(): void
    {
        // Every class pointer in $required_field_model_map
        // must implement the Importable marker interface, or the
        // defense-in-depth check in requiredForType() will refuse the
        // dispatch for a legitimate type. Catches the case where someone
        // adds a new import type to the map without also adding
        // `implements Importable` to the model class.
        $component = new Importer;

        foreach ($component->required_field_model_map as $type => $entry) {
            [$modelClass] = $entry;
            $this->assertTrue(
                is_subclass_of($modelClass, \App\Models\Contracts\Importable::class),
                "map entry for type '{$type}' points at '{$modelClass}' which does not implement \\App\\Models\\Contracts\\Importable"
            );
        }
    }
}
