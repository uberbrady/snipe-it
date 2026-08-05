<?php

namespace App\Observers;

use App\Models\Actionlog;
use App\Models\Component;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;

class ComponentObserver
{
    /**
     * Listen to the User created event.
     *
     * @return void
     */
    public function updated(Component $component)
    {

        $changed = [];

        foreach ($component->getRawOriginal() as $key => $value) {
            // Check and see if the value changed
            if ($component->getRawOriginal()[$key] != $component->getAttributes()[$key]) {
                $changed[$key]['old'] = $component->getRawOriginal()[$key];
                $changed[$key]['new'] = $component->getAttributes()[$key];
            }
        }

        if (count($changed) > 0) {
            $logAction = new Actionlog;
            $logAction->item_type = Component::class;
            $logAction->item_id = $component->id;
            $logAction->created_at = date('Y-m-d H:i:s');
            $logAction->action_date = date('Y-m-d H:i:s');
            $logAction->created_by = auth()->id();
            // order_number moved off Component to the Orders / OrderItems
            // data model — nothing to capture on the update log anymore.
            $logAction->log_meta = json_encode($changed);
            if ($component->imported) {
                $logAction->setActionSource('importer');
            }
            $logAction->logaction('update');
        }

    }

    /**
     * Listen to the Component created event when
     * a new component is created.
     *
     * @return void
     */
    public function created(Component $component)
    {
        $attrs = $component->getAttributes();
        $initialQty = (int) ($attrs['qty'] ?? 0);

        // Skip Order + OrderItem when qty=0/null (container-only).
        // See AccessoryObserver::created for the full rationale.
        $orderItem = null;
        if ($initialQty > 0) {
            $currency = ($component->location && $component->location->currency !== '' && $component->location->currency !== null)
                ? $component->location->currency
                : Setting::getSettings()?->default_currency;

            $order = new Order([
                'order_number' => null,
                'supplier_id' => null,
                'company_id' => $component->company_id,
                'purchase_date' => null,
                'currency' => $currency,
            ]);
            $order->created_by = $component->created_by ?? auth()->id();
            $order->save();

            $orderItem = new OrderItem([
                'order_id' => $order->id,
                'item_type' => Component::class,
                'item_id' => $component->id,
                'qty' => $initialQty,
                'price' => null,
            ]);
            $orderItem->created_by = $component->created_by ?? auth()->id();
            $orderItem->save();
        }

        $logAction = new Actionlog;
        $logAction->item_type = Component::class;
        $logAction->item_id = $component->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->action_date = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        $logAction->quantity = $initialQty;
        $logAction->order_item_id = $orderItem?->id;
        if ($component->imported) {
            $logAction->setActionSource('importer');
        }
        $logAction->logaction('create');
    }

    /**
     * Listen to the Component deleting event.
     *
     * @return void
     */
    public function deleting(Component $component)
    {
        $logAction = new Actionlog;
        $logAction->item_type = Component::class;
        $logAction->item_id = $component->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->action_date = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        $logAction->logaction('delete');
    }
}
