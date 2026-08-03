<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index action_logs.order_number so the history-tab search (which sends
 * WHERE order_number LIKE ?) and the column-sort (ORDER BY order_number)
 * don't sequential-scan a table that grows unbounded on busy installs.
 * Search is a prefix LIKE via the bootstrap-table search box, so a plain
 * btree index is enough — no fulltext needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            $table->index('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            $table->dropIndex(['order_number']);
        });
    }
};
