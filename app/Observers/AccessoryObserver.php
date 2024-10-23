<?php

namespace App\Observers;

use App\Models\Accessory;
use App\Models\Actionlog;

class AccessoryObserver
{
    /**
     * Listen to the User created event.
     *
     * @param  Accessory  $accessory
     * @return void
     */
    public function updated(Accessory $accessory)
    {
        // FIXME - do the same kind of thing here?

        $logAction = new Actionlog();
        $logAction->item_type = Accessory::class;
        $logAction->item_id = $accessory->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        $logAction->quantity = $accessory->qty;
        $logAction->logaction('update', [
            'order_number'  => $accessory->order_number,
            'purchase_date' => $accessory->purchase_date,
            'purchase_cost' => $accessory->purchase_cost,
            'supplier_id'   => $accessory->supplier_id,
        ]);
    }

    /**
     * Listen to the Accessory created event when
     * a new accessory is created.
     *
     * @param  Accessory  $accessory
     * @return void
     */
    public function created(Accessory $accessory)
    {
        $logAction = new Actionlog();
        $logAction->item_type = Accessory::class;
        $logAction->item_id = $accessory->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        if($accessory->imported) {
            $logAction->setActionSource('importer');
        }
        $logAction->quantity = $accessory->qty;
        $logAction->logaction('create', [
            'order_number'  => $accessory->order_number,
            'purchase_date' => $accessory->purchase_date,
            'purchase_cost' => $accessory->purchase_cost,
            'supplier_id'   => $accessory->supplier_id,
        ]);
    }

    /**
     * Listen to the Accessory deleting event.
     *
     * @param  Accessory  $accessory
     * @return void
     */
    public function deleting(Accessory $accessory)
    {
        $logAction = new Actionlog();
        $logAction->item_type = Accessory::class;
        $logAction->item_id = $accessory->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        $logAction->logaction('delete');
    }
}
