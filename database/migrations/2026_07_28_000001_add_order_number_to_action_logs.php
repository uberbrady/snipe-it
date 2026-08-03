<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('action_logs', 'order_number')) {
                $table->string('order_number')->nullable()->after('note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            if (Schema::hasColumn('action_logs', 'order_number')) {
                $table->dropColumn('order_number');
            }
        });
    }
};
