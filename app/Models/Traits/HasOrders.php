<?php

namespace App\Models\Traits;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
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
     * them. Soft-delete on the parent does not propagate. The Order
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

    /**
     * Sort scope that lets the bootstrap-table sortable header for an
     * order-number column keep working after the parent order_number
     * column moved to Orders. Attaches a correlated subquery selecting
     * the latest Order.order_number for each row (matched via the
     * polymorphic order_items pivot), then orders by that alias.
     *
     * "Latest" is defined as the Order with the most recent created_at
     * among all OrderItems that reference this row. Rows with no Order
     * history sort last on `asc` / first on `desc` (natural NULL sort
     * behavior on both MySQL and SQLite).
     *
     * Uses addSelect so preceding withCount subqueries survive — a
     * plain select() would wipe them and re-introduce N+1 downstream.
     */
    public function scopeOrderByOrderNumber(Builder $query, string $direction = 'asc'): Builder
    {
        $modelTable = (new static)->getTable();

        return $query->addSelect([
            'sort_order_number' => OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereColumn('order_items.item_id', $modelTable.'.id')
                ->where('order_items.item_type', static::class)
                ->orderByDesc('orders.created_at')
                ->limit(1)
                ->select('orders.order_number'),
        ])->orderBy('sort_order_number', $direction);
    }
}
