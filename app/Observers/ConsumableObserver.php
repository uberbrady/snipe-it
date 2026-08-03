<?php

namespace App\Observers;

use App\Models\Actionlog;
use App\Models\Consumable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConsumableObserver
{
    /**
     * Listen to the User created event.
     *
     * @return void
     */
    public function updated(Consumable $consumable)
    {

        $changed = [];

        foreach ($consumable->getRawOriginal() as $key => $value) {
            // Check and see if the value changed
            if ($consumable->getRawOriginal()[$key] != $consumable->getAttributes()[$key]) {
                $changed[$key]['old'] = $consumable->getRawOriginal()[$key];
                $changed[$key]['new'] = $consumable->getAttributes()[$key];
            }
        }

        if (count($changed) > 0) {
            $logAction = new Actionlog;
            $logAction->item_type = Consumable::class;
            $logAction->item_id = $consumable->id;
            $logAction->created_at = date('Y-m-d H:i:s');
            $logAction->created_by = auth()->id();
            // Accessor on Consumable returns null for order_number; read
            // the raw stored value so the log captures the actual value.
            $logAction->order_number = $consumable->getRawOriginal('order_number');
            $logAction->log_meta = json_encode($changed);
            $logAction->logaction('update');
        }
    }

    /**
     * Listen to the Consumable created event when
     * a new consumable is created.
     *
     * @return void
     */
    public function created(Consumable $consumable)
    {
        $logAction = new Actionlog;
        $logAction->item_type = Consumable::class;
        $logAction->item_id = $consumable->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        // See AccessoryObserver::created for the getAttributes() rationale
        // (getRawOriginal is empty during the `created` event because
        // syncOriginal hasn't run yet on a fresh model).
        $attrs = $consumable->getAttributes();
        $logAction->order_number = $attrs['order_number'] ?? null;
        // Capture the initial on-hand qty so the create log gives auditors
        // a "started with N units" anchor point. Subsequent QuantityAdjust
        // logs record deltas, not running totals.
        $logAction->quantity = (int) ($attrs['qty'] ?? 0);
        if ($consumable->imported) {
            $logAction->setActionSource('importer');
        }
        $logAction->logaction('create');
    }

    /**
     * Listen to the Consumable deleting event.
     *
     * @return void
     */
    public function deleting(Consumable $consumable)
    {

        $consumable->users()->detach();
        $uploads = $consumable->uploads;

        foreach ($uploads as $file) {
            try {
                Storage::delete('private_uploads/consumables/'.$file->filename);
                $file->delete();
            } catch (\Exception $e) {
                Log::info($e);
            }
        }

        try {
            Storage::disk('public')->delete('consumables/'.$consumable->image);
        } catch (\Exception $e) {
            Log::info($e);
        }

        $consumable->image = null;
        $consumable->save();

        $logAction = new Actionlog;
        $logAction->item_type = Consumable::class;
        $logAction->item_id = $consumable->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        $logAction->logaction('delete');
    }
}
