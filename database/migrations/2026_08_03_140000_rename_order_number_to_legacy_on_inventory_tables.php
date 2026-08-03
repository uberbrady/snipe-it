<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename `order_number` to `legacy_order_number` on the three inventory-
 * style tables (accessories, consumables, components). These models moved
 * to a per-QuantityAdjust event model where each replenishment carries
 * its own order number on the action_log row, and the parent-level
 * column no longer represents current state. Renaming forces every
 * remaining call site to declare intent (either look up via action_logs
 * or explicitly opt into the legacy value) instead of silently reading
 * a stale value.
 *
 * Asset and License intentionally keep their `order_number` column
 * because their semantics are per-unit and the parent-level value is
 * still meaningful.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['accessories', 'consumables', 'components'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->renameColumn('order_number', 'legacy_order_number');
            });
        }
    }

    public function down(): void
    {
        foreach (['accessories', 'consumables', 'components'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->renameColumn('legacy_order_number', 'order_number');
            });
        }
    }
};
