<?php

namespace App\Observers;

use App\Models\Actionlog;
use App\Models\Component;

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
            // Accessor on Component returns null for order_number; read
            // the raw stored value so the log captures the actual value.
            $logAction->order_number = $component->getRawOriginal('order_number');
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
        $logAction = new Actionlog;
        $logAction->item_type = Component::class;
        $logAction->item_id = $component->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->action_date = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        // See AccessoryObserver::created for the getAttributes() rationale
        // (getRawOriginal is empty during the `created` event because
        // syncOriginal hasn't run yet on a fresh model).
        $attrs = $component->getAttributes();
        $logAction->order_number = $attrs['order_number'] ?? null;
        // Capture the initial on-hand qty so the create log gives auditors
        // a "started with N units" anchor point. Subsequent QuantityAdjust
        // logs record deltas, not running totals.
        $logAction->quantity = (int) ($attrs['qty'] ?? 0);
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
