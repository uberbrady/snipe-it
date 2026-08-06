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
        $initialQty = (int) ($attrs['qty'] ?? 0);

        // Skip Order + OrderItem when qty=0/null (container-only).
        // See AccessoryObserver::created for the full rationale.
        $orderItem = null;
        if ($initialQty > 0) {
            $currency = ($consumable->location && $consumable->location->currency !== '' && $consumable->location->currency !== null)
                ? $consumable->location->currency
                : Setting::getSettings()?->default_currency;

            $order = new Order([
                'order_number' => null,
                'supplier_id' => null,
                'company_id' => $consumable->company_id,
                'purchase_date' => null,
                'currency' => $currency,
            ]);
            $order->created_by = $consumable->created_by ?? auth()->id();
            $order->save();

            $orderItem = new OrderItem([
                'order_id' => $order->id,
                'item_type' => Consumable::class,
                'item_id' => $consumable->id,
                'qty' => $initialQty,
                'price' => null,
            ]);
            $orderItem->created_by = $consumable->created_by ?? auth()->id();
            $orderItem->save();
        }

        $logAction = new Actionlog;
        $logAction->item_type = Consumable::class;
        $logAction->item_id = $consumable->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        // See AssetModelObserver::created for the seeder-friendly
        // auth fallback rationale.
        $logAction->created_by = auth()->id() ?? $consumable->created_by;
        $logAction->quantity = $initialQty;
        $logAction->order_item_id = $orderItem?->id;
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
