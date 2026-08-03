<?php

namespace App\Models\Traits;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Wires an inventory model up to the polymorphic Orders + OrderItems
 * data model. Consumed by Accessory / Consumable / Component (via the
 * AdjustsQuantity flow) and by Asset / License (for historical order
 * lookups even though they don't get replenished per-event).
 *
 * `orderItems()` is the direct polymorphic relation — one row per line
 * on an Order that referenced this model. `orders()` is a
 * HasManyThrough convenience that surfaces the underlying Order rows
 * so free-text search on
 * `$searchableRelations = ['orders' => ['order_number']]` lands on the
 * joined column without the caller having to walk through order_items
 * manually.
 */
trait HasOrders
{
    /**
     * Hook a model lifecycle listener so that force-deleting an
     * inventory row (Accessory / Consumable / Component / Asset /
     * License) soft-deletes every OrderItem pointing at it. Preserves
     * the acquisition ledger — the Order row and its (now-trashed)
     * OrderItem lines remain queryable via `withTrashed()` for
     * historical reports, while ordinary `->orderItems()` reads exclude
     * them. Soft-delete on the parent does not propagate; the Order
     * data model treats a soft-deleted inventory row as still-existing.
     */
    protected static function bootHasOrders(): void
    {
        static::deleting(function ($model) {
            if (! method_exists($model, 'isForceDeleting') || ! $model->isForceDeleting()) {
                return;
            }

            OrderItem::where('item_type', static::class)
                ->where('item_id', $model->id)
                ->delete();
        });
    }

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
