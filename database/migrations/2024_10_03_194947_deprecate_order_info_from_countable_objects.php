<?php

use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\License;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    static $first_class_objects = [
        'accessories' => Accessory::class,
        'components' => Component::class,
        'consumables' => Consumable::class,
        'licenses' => License::class
    ];

    static $order_related_columns = [
        'order_number',
        'supplier_id',
        'purchase_date',
        'purchase_cost',
        'qty'
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (static::$first_class_objects as $table_name => $class_name) {
            foreach(static::$order_related_columns as $column_name) {
                if($table_name == "licenses" && $column_name == "qty") {
                    //Licenses don't have "qty" they have "seats" which is OK. Maybe. If not, we'll fix it in a later migration
                    continue;
                }
                Schema::table($table_name, function (Blueprint $table) use ($column_name) {
                    $table->renameColumn($column_name, "deprecated_".$column_name);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (static::$first_class_objects as $table_name => $class_name) {
            foreach(static::$order_related_columns as $column_name) {
                if($table_name == "licenses" && $column_name == "qty") {
                    //Licenses don't have "qty" they have "seats" which is OK. Maybe. If not, we'll fix it in a later migration
                    continue;
                }
                Schema::table($table_name, function (Blueprint $table) use ($column_name) {
                    $table->renameColumn("deprecated_".$column_name, $column_name);
                });
            }
        }
    }
};
