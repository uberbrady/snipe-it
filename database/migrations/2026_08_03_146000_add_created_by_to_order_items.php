<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-line authorship to order_items. Order.created_by records
 * who opened the transaction, but a single Order can accumulate lines
 * from multiple operators over time (Alice has Order-42 with two lines,
 * Bob later replenishes under the same Order-42 with three more). Storing
 * created_by on the OrderItem itself preserves that per-line context.
 *
 * Nullable so historical rows written before this migration back-fill
 * from the parent Order.created_by, and so admins clearing themselves
 * out (soft-delete workflow) don't cascade into false authorship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('price');
            $table->index('created_by');
        });

        // Backfill existing rows from the parent Order.created_by so
        // history-tab renders and reports don't show a null column for
        // every pre-migration line.
        DB::statement('
            UPDATE order_items
            JOIN orders ON orders.id = order_items.order_id
            SET order_items.created_by = orders.created_by
            WHERE order_items.created_by IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
