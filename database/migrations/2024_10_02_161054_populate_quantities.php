<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('action_logs')->whereIn('action_type',['checkout'])->update(['quantity' => -1]);
        DB::table('action_logs')->whereIn('action_type',['checkin from'])->update(['quantity' => 1]);
        DB::table('action_logs')->whereIn('action_type',['add seats','delete seats'])->orderBy('id')->chunk(100, function (Collection $logs) {
            foreach ($logs as $log) {
                switch ($log->action_type) {
                    case 'add seats':
                        $sign = 1;
                        break;
                    case 'delete seats':
                        $sign = -1;
                        break;
                    default:
                        throw new \Exception("Unknown action type ".$log->action_type);
                }
                $number = preg_replace("/\D+/","", $log->note);
                DB::table('action_logs')->where('id', $log->id)->update(['quantity' => $sign * $number]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // UPDATE action_logs SET quantity=null;
        DB::table('action_logs')->update(['quantity' => null]);
    }
};
