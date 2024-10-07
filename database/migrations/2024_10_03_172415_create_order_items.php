<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('action_log_id')->unique()->notNull();
            $table->string('order_number')->index()->nullable();
            $table->integer('supplier_id')->nullable();
            $table->integer('purchase_date')->nullable();
            $table->decimal('purchase_cost',20,2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->drop();
        });
    }
};
