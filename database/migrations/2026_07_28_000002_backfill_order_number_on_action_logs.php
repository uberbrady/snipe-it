<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill for existing accessory / consumable / component action_logs.
 *
 * Two fields get filled from the parent's current row:
 *
 * 1. order_number on create and update rows. The parent's stored value
 *    replaces null in the log. Rows where the observer eventually
 *    populated order_number correctly stay untouched (idempotent via
 *    whereNull).
 * 2. quantity on create rows. The observers only started capturing the
 *    initial on-hand qty at write time in the AdjustsQuantity work, so
 *    any pre-existing create log has quantity = 0. Backfill from the
 *    parent's current qty column. Only touches rows where quantity is
 *    null or 0 so re-runs and observer-written rows stay untouched.
 *
 * Both use the item's CURRENT parent-column state, not the true
 * at-log-time state — any item edited or adjusted since the log was
 * written picks up the newer value. Acceptable one-time trade-off;
 * new log entries are accurate because the observers now capture at
 * write time.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();

        foreach ([
            \App\Models\Accessory::class => 'accessories',
            \App\Models\Consumable::class => 'consumables',
            \App\Models\Component::class => 'components',
        ] as $modelClass => $table) {
            DB::table('action_logs')
                ->where('item_type', $modelClass)
                ->whereIn('action_type', ['create', 'update'])
                ->whereNull('order_number')
                ->update([
                    'order_number' => DB::raw(
                        "(SELECT order_number FROM {$prefix}{$table} "
                        ."WHERE {$prefix}{$table}.id = {$prefix}action_logs.item_id "
                        .'LIMIT 1)'
                    ),
                ]);

            DB::table('action_logs')
                ->where('item_type', $modelClass)
                ->where('action_type', 'create')
                ->where(function ($q) {
                    $q->whereNull('quantity')->orWhere('quantity', 0);
                })
                ->update([
                    'quantity' => DB::raw(
                        "(SELECT qty FROM {$prefix}{$table} "
                        ."WHERE {$prefix}{$table}.id = {$prefix}action_logs.item_id "
                        .'LIMIT 1)'
                    ),
                ]);
        }
    }

    public function down(): void
    {
        // No-op: we can't distinguish backfilled rows from ones the
        // observer wrote after the fact, so undoing would risk erasing
        // real order references. If you truly need to reverse, run the
        // schema-drop migration instead (which drops the column).
    }
};
