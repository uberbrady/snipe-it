<?php

use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\License;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    static $first_class_objects = [
        'accessories' => Accessory::class,
        'components' => Component::class,
        'consumables' => Consumable::class,
        'licenses' => License::class
    ];

    static $note_string = 'Import of initial quantity by migration';
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //accessory,component,consumable,
        foreach(self::$first_class_objects as $table_name => $class){
            DB::table($table_name)->orderBy('id')->chunk(100, function (Collection $collection) use ($class) {
                foreach($collection as $item){
                    if ($class == License::class) {
                        $quantity = $item->seats;
                    } else {
                        $quantity = $item->qty;
                    }
                    DB::table('action_logs')->insert([
                        'note' => self::$note_string,
                        'action_type' => 'PICK A GOOD WORD HERE!!!!', //FIXME
                        'item_id' => $item->id,
                        'item_type' => $class,
                        'quantity' => $quantity,
                        'action_date' => new Carbon('January 1 1970'), //Wait, should we do this *here*? FIXME (maybe the initial date?) 1/1/1970?
                    ]);
                    //do something with the order_number? Add it.
                }
            });
        }

        //license are different
        //asset are unaffected?
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach(self::$first_class_objects as $table_name => $class){
            DB::table('action_logs')->where(['note' => static::$note_string])->delete();
        }
    }
};
