<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the now-orphaned `order_number` string columns from the five
 * inventory tables. Runs after the `backfill_orders_from_inventory_tables`
 * migration has moved every non-null value into a real Order + OrderItem
 * pair; anything still pointing at the parent column is either null or
 * legitimately stale.
 *
 * Deliberately does not touch Asset / License business logic — those
 * models had already dropped `order_number` from their fillable /
 * searchable / validation arrays in the same PR. The DB column is the
 * last piece of the old shape.
 */
return new class extends Migration
{
    private const TABLES = [
        'accessories',
        'consumables',
        'components',
        'assets',
        'licenses',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'order_number')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('order_number');
            });
        }
    }

    public function down(): void
    {
        // Restore as a plain nullable string column. No data recovery —
        // callers that want the pre-Orders values back need to roll the
        // backfill migration first and then rehydrate this column from
        // the OrderItem rows themselves. Leaving that manual because
        // the rehydration is inherently lossy (many-to-one order → one
        // row here means we'd have to pick one Order per parent row).
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'order_number')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->string('order_number')->nullable();
            });
        }
    }
};
