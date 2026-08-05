<?php

namespace App\Observers;

use App\Models\Accessory;
use App\Models\Actionlog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;

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
        $attrs = $accessory->getAttributes();
        $qty = max(1, (int) ($attrs['qty'] ?? 0));

        // Every accessory creation IS an acquisition transaction. Write
        // the initial Order + OrderItem using what's on the model. The
        // order_number stays null since the parent has no column for it
        // any more — controllers doing form-driven creates enrich the
        // Order with the form-supplied order_number / currency after
        // save. Factories, seeders, importers, and any other callers
        // just get the parent-attribute defaults, which keeps their
        // rows discoverable via the Orders ledger.
        $currency = ($accessory->location && $accessory->location->currency !== '' && $accessory->location->currency !== null)
            ? $accessory->location->currency
            : Setting::getSettings()?->default_currency;

        $order = new Order([
            'order_number' => null,
            'supplier_id' => $accessory->supplier_id,
            'company_id' => $accessory->company_id,
            'purchase_date' => $accessory->purchase_date,
            'currency' => $currency,
        ]);
        $order->created_by = $accessory->created_by ?? auth()->id();
        $order->save();

        $orderItem = new OrderItem([
            'order_id' => $order->id,
            'item_type' => Accessory::class,
            'item_id' => $accessory->id,
            'qty' => $qty,
            'price' => $accessory->purchase_cost,
        ]);
        $orderItem->created_by = $accessory->created_by ?? auth()->id();
        $orderItem->save();

        $logAction = new Actionlog;
        $logAction->item_type = Accessory::class;
        $logAction->item_id = $accessory->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        // Capture the initial on-hand qty so the create log gives auditors
        // a "started with N units" anchor point. Subsequent QuantityAdjust
        // logs record deltas, not running totals.
        $logAction->quantity = (int) ($attrs['qty'] ?? 0);
        // Link the create log to the OrderItem so the history-tab
        // order-number column resolves to the initial Order (matches
        // how QuantityAdjust logs surface later replenishments).
        $logAction->order_item_id = $orderItem->id;
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
