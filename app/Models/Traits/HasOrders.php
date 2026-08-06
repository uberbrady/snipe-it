<?php

namespace App\Models\Traits;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Supplier;
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
     * Count of distinct Orders this item has been *purchased* on. Feeds
     * the info-panel's "Total Orders" row and the Orders-tab badge.
     * Filters to lines with positive qty so corrections / consumption
     * events (0- or negative-qty OrderItems) don't inflate the count —
     * those aren't purchases, and treating them as such was misleading
     * when a lifecycle had more corrections than actual acquisitions.
     * DISTINCT because one Order can carry multiple positive lines for
     * the same item under staggered receipts.
     */
    public function ordersCount(): int
    {
        return (int) $this->orders()
            ->where('order_items.qty', '>', 0)
            ->distinct()
            ->count('orders.id');
    }

    /**
     * Prefill context for the adjust-quantity modal and the info-panel's
     * "last" fields. Prefers the most recent Order/OrderItem when one
     * exists (companies drift — the last supplier they actually bought
     * from beats a stale parent "default" field), and falls back to the
     * parent's `default_*` template values on items that have never been
     * ordered yet.
     *
     * Returns null only when there is no last-order data AND no template
     * defaults on the parent — a brand-new item with no history to seed
     * from.
     *
     * One query per invocation. Cheap on the view page (1 model per
     * page); eager-load on index pages.
     *
     * @return array{unit_cost: ?string, currency: ?string, purchase_date: ?string, supplier_id: ?int}|null
     */
    public function lastOrderDefaults(): ?array
    {
        $line = $this->orderItems()
            ->with('order:id,currency,purchase_date,supplier_id')
            ->latest('id')
            ->first();

        if ($line) {
            return [
                'unit_cost' => $line->price !== null ? (string) $line->price : null,
                'currency' => $line->order?->currency ?: null,
                'purchase_date' => $line->order?->purchase_date?->toDateString(),
                'supplier_id' => $line->order?->supplier_id,
            ];
        }

        $defaultSupplier = $this->getAttribute('default_supplier_id');
        $defaultCost = $this->getAttribute('default_purchase_cost');

        if ($defaultSupplier === null && $defaultCost === null) {
            return null;
        }

        return [
            'unit_cost' => $defaultCost !== null ? (string) $defaultCost : null,
            'currency' => null,
            'purchase_date' => null,
            'supplier_id' => $defaultSupplier !== null ? (int) $defaultSupplier : null,
        ];
    }

    /**
     * Resolve a Supplier for the "last acquisition" view (transformers,
     * info-panel, report callbacks). Same fallback ladder as
     * lastOrderDefaults(): last Order.supplier_id wins, falls back to
     * the parent's default_supplier_id template value on items with no
     * order history. Returns null when both are unset.
     *
     * Prefers walking eager-loaded relations (orderItems.order.supplier)
     * when the caller pre-loaded them; otherwise issues one query for
     * the latest OrderItem's Order.supplier_id and then hydrates the
     * Supplier. Callers rendering a list should always eager-load to
     * avoid N+1.
     */
    public function lastAcquisitionSupplier(): ?Supplier
    {
        $cachedSupplier = $this->cachedLastOrderSupplier();
        if ($cachedSupplier !== null) {
            return $cachedSupplier;
        }

        $supplierId = $this->lastOrderSupplierId() ?? $this->getAttribute('default_supplier_id');

        return $supplierId ? Supplier::find($supplierId) : null;
    }

    /**
     * Fast-path: return the eager-loaded Supplier from the
     * `orderItems.order.supplier` chain if the caller pre-loaded it.
     * Callers rendering a list should eager-load this chain to avoid
     * an N+1 through Supplier::find below.
     */
    private function cachedLastOrderSupplier(): ?Supplier
    {
        if (! $this->relationLoaded('orderItems')) {
            return null;
        }

        $order = $this->orderItems->sortByDesc('id')->first()?->order;

        if ($order?->relationLoaded('supplier') && $order->supplier) {
            return $order->supplier;
        }

        return null;
    }

    /**
     * Slow-path: read the latest OrderItem's Order.supplier_id via a
     * fresh query. Called only when the eager-loaded fast-path missed.
     */
    private function lastOrderSupplierId(): ?int
    {
        if ($this->relationLoaded('orderItems')) {
            return $this->orderItems->sortByDesc('id')->first()?->order?->supplier_id;
        }

        return $this->orderItems()->with('order:id,supplier_id')->latest('id')->first()?->order?->supplier_id;
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

    /**
     * Sort by the most recent OrderItem's price. Rows with no order
     * history sort last on `asc` / first on `desc` (natural NULL sort
     * behavior on both MySQL and SQLite). Uses addSelect for the same
     * reason as scopeOrderByOrderNumber above.
     */
    public function scopeOrderByLastPurchaseCost(Builder $query, string $direction = 'asc'): Builder
    {
        $modelTable = (new static)->getTable();

        return $query->addSelect([
            'sort_last_purchase_cost' => OrderItem::query()
                ->whereColumn('order_items.item_id', $modelTable.'.id')
                ->where('order_items.item_type', static::class)
                ->orderByDesc('order_items.id')
                ->limit(1)
                ->select('order_items.price'),
        ])->orderBy('sort_last_purchase_cost', $direction);
    }

    /**
     * Sort by the most recent Order.purchase_date across this row's
     * OrderItems. See scopeOrderByLastPurchaseCost for the null-sort
     * caveat.
     */
    public function scopeOrderByLastPurchaseDate(Builder $query, string $direction = 'asc'): Builder
    {
        $modelTable = (new static)->getTable();

        return $query->addSelect([
            'sort_last_purchase_date' => OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereColumn('order_items.item_id', $modelTable.'.id')
                ->where('order_items.item_type', static::class)
                ->orderByDesc('order_items.id')
                ->limit(1)
                ->select('orders.purchase_date'),
        ])->orderBy('sort_last_purchase_date', $direction);
    }

    /**
     * Sort by the sum of (qty * price) across every OrderItem attached
     * to this row. Matches the info-panel's total_cost display so a
     * click on the total-cost column header lands rows in the same
     * order the panel shows them.
     */
    public function scopeOrderByTotalOrderCost(Builder $query, string $direction = 'asc'): Builder
    {
        $modelTable = (new static)->getTable();

        return $query->addSelect([
            'sort_total_order_cost' => OrderItem::query()
                ->whereColumn('order_items.item_id', $modelTable.'.id')
                ->where('order_items.item_type', static::class)
                ->selectRaw('COALESCE(SUM(order_items.qty * order_items.price), 0)'),
        ])->orderBy('sort_total_order_cost', $direction);
    }
}
