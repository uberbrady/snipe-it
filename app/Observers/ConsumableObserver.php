<?php

namespace App\Observers;

use App\Models\Actionlog;
use App\Models\Consumable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
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
            // order_number moved off Consumable to the Orders / OrderItems
            // data model — nothing to capture on the update log anymore.
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
        $attrs = $consumable->getAttributes();
        $qty = max(1, (int) ($attrs['qty'] ?? 0));

        // Every consumable creation IS an acquisition transaction.
        // See AccessoryObserver::created for the full rationale — same
        // pattern: write the initial Order + OrderItem using parent
        // attributes, controllers enrich with form-supplied fields after
        // save, factories / seeders / importers get parent-only data.
        $currency = ($consumable->location && $consumable->location->currency !== '' && $consumable->location->currency !== null)
            ? $consumable->location->currency
            : Setting::getSettings()?->default_currency;

        $order = Order::create([
            'order_number' => null,
            'supplier_id' => $consumable->supplier_id,
            'company_id' => $consumable->company_id,
            'purchase_date' => $consumable->purchase_date,
            'currency' => $currency,
            'created_by' => $consumable->created_by ?? auth()->id(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'item_type' => Consumable::class,
            'item_id' => $consumable->id,
            'qty' => $qty,
            'price' => $consumable->purchase_cost,
        ]);

        $logAction = new Actionlog;
        $logAction->item_type = Consumable::class;
        $logAction->item_id = $consumable->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        // Capture the initial on-hand qty so the create log gives auditors
        // a "started with N units" anchor point. Subsequent QuantityAdjust
        // logs record deltas, not running totals.
        $logAction->quantity = (int) ($attrs['qty'] ?? 0);
        $logAction->order_item_id = $orderItem->id;
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
