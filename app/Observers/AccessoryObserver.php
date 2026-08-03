<?php

namespace App\Observers;

use App\Models\Accessory;
use App\Models\Actionlog;

class AccessoryObserver
{
    /**
     * Listen to the User created event.
     *
     * @return void
     */
    public function updated(Accessory $accessory)
    {
        $changed = [];

        foreach ($accessory->getRawOriginal() as $key => $value) {
            if ($key === 'updated_at') {
                continue;
            }
            if ($accessory->getRawOriginal()[$key] != $accessory->getAttributes()[$key]) {
                $changed[$key]['old'] = $accessory->getRawOriginal()[$key];
                $changed[$key]['new'] = $accessory->getAttributes()[$key];
            }
        }

        if (count($changed) > 0) {
            $logAction = new Actionlog;
            $logAction->item_type = Accessory::class;
            $logAction->item_id = $accessory->id;
            $logAction->created_at = date('Y-m-d H:i:s');
            $logAction->created_by = auth()->id();
            // order_number moved off Accessory to the Orders / OrderItems
            // data model — nothing to capture on the update log anymore.
            $logAction->log_meta = json_encode($changed);
            $logAction->logaction('update');
        }
    }

    /**
     * Listen to the Accessory created event when
     * a new accessory is created.
     *
     * @return void
     */
    public function created(Accessory $accessory)
    {
        $logAction = new Actionlog;
        $logAction->item_type = Accessory::class;
        $logAction->item_id = $accessory->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        $attrs = $accessory->getAttributes();
        // order_number moved off Accessory to the Orders / OrderItems
        // data model — the create log no longer captures it directly.
        // Capture the initial on-hand qty so the create log gives auditors
        // a "started with N units" anchor point. Subsequent QuantityAdjust
        // logs record deltas, not running totals.
        $logAction->quantity = (int) ($attrs['qty'] ?? 0);
        if ($accessory->imported) {
            $logAction->setActionSource('importer');
        }
        $logAction->logaction('create');
    }

    /**
     * Listen to the Accessory deleting event.
     *
     * @return void
     */
    public function deleting(Accessory $accessory)
    {
        $logAction = new Actionlog;
        $logAction->item_type = Accessory::class;
        $logAction->item_id = $accessory->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        $logAction->logaction('delete');
    }
}
