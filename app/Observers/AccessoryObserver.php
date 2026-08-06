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
        $initialQty = (int) ($attrs['qty'] ?? 0);

        // Only write an Order + OrderItem when the create carried real
        // stock (qty > 0). qty=0/null means "container-only" — a bare
        // accessory row without an initial acquisition — and there's no
        // transaction to record. Later adjust-quantity events write
        // their own Order + OrderItem when stock actually arrives.
        //
        // For qty > 0, the Order + OrderItem written here are
        // placeholders. Transaction metadata (supplier_id, purchase_date,
        // purchase_cost, currency, order_number) only lives on Orders /
        // OrderItems now — form-driven creates enrich it via the
        // controller's enrichInitialOrderFromRequest(), and factory /
        // seeder paths fill it via an afterCreating hook.
        $orderItem = null;
        if ($initialQty > 0) {
            $currency = ($accessory->location && $accessory->location->currency !== '' && $accessory->location->currency !== null)
                ? $accessory->location->currency
                : Setting::getSettings()?->default_currency;

            $order = new Order([
                'order_number' => null,
                'supplier_id' => null,
                'company_id' => $accessory->company_id,
                'purchase_date' => null,
                'currency' => $currency,
            ]);
            $order->created_by = $accessory->created_by ?? auth()->id();
            $order->save();

            $orderItem = new OrderItem([
                'order_id' => $order->id,
                'item_type' => Accessory::class,
                'item_id' => $accessory->id,
                'qty' => $initialQty,
                'price' => null,
            ]);
            $orderItem->created_by = $accessory->created_by ?? auth()->id();
            $orderItem->save();
        }

        $logAction = new Actionlog;
        $logAction->item_type = Accessory::class;
        $logAction->item_id = $accessory->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        // See AssetModelObserver::created for the seeder-friendly
        // auth fallback rationale.
        $logAction->created_by = auth()->id() ?? $accessory->created_by;
        // Capture the initial on-hand qty so the create log gives auditors
        // a "started with N units" anchor point. Subsequent QuantityAdjust
        // logs record deltas, not running totals.
        $logAction->quantity = $initialQty;
        // Link the create log to the OrderItem when one exists. For
        // container-only creates (qty=0) the log has no OrderItem to
        // point at.
        $logAction->order_item_id = $orderItem?->id;
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
