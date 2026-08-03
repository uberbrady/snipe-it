<?php

namespace App\Models\Traits;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Wires an inventory model up to the polymorphic Orders + OrderItems
 * data model. Consumed by Accessory / Consumable / Component (via the
 * AdjustsQuantity flow) and by Asset / License (for historical PO
 * lookups even though they don't get replenished per-event).
 *
 * `orderItems()` is the direct polymorphic relation — one row per line
 * on a PO that referenced this model. `orders()` is a HasManyThrough
 * convenience that surfaces the underlying Order rows so free-text
 * search on `$searchableRelations = ['orders' => ['order_number']]`
 * lands on the joined column without the caller having to walk through
 * order_items manually.
 */
trait HasOrders
{
    public function orderItems(): MorphMany
    {
        return $this->morphMany(OrderItem::class, 'item');
    }

    /**
     * HasManyThrough into Orders via the polymorphic order_items pivot.
     * The extra where on order_items.item_type filters the pivot to
     * lines that reference THIS model class, which HasManyThrough on
     * its own can't do because it doesn't understand morph maps.
     */
    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(
            Order::class,
            OrderItem::class,
            'item_id',   // FK on order_items pointing at model
            'id',        // FK on orders (its primary key)
            'id',        // local key on this model
            'order_id',  // FK on order_items pointing at orders
        )->where('order_items.item_type', static::class);
    }
}
