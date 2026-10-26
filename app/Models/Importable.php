<?php

namespace App\Models;

/**
 * Marker interface for models the Importer wizard knows how to import.
 *
 * The Livewire Importer at app/Livewire/Importer.php cross-checks every
 * class string from `$required_field_model_map` against this interface
 * before `new $modelClass`. The property itself is `#[Locked]`, so the
 * Livewire hydration layer already rejects client writes, but this
 * interface check is defense in depth. Even if a future Livewire
 * regression bypasses Locked, the `new` dispatch stays bounded to the
 * exact set of models that opt in by implementing this interface.
 *
 * No methods. The models that implement it already carry everything the
 * importer needs via their own `$rules` and attribute columns. Adding
 * this interface to a new model is the deliberate signal that "yes, the
 * importer wizard may instantiate this." Introduced with GHSA-r43j-45c3
 * -9vvw.
 */
interface Importable
{
    /**
     * The validation-rule array the importer walks to decide which
     * target columns are required at the mapping-wizard step. Every
     * Snipe-IT model that implements this interface already exposes
     * `getRules()` via Watson's ValidatingTrait, so declaring it here
     * is just codifying the existing contract so static analysis can
     * see the shape instead of resolving the call through the trait.
     *
     * @return array<string, mixed>
     */
    public function getRules();
}
