<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An acquisition record for inventory. One row per real-world order with
 * an optional order_number, supplier, purchase_date, and company scope.
 * Line items (the actual things acquired and their quantities / prices)
 * live on the OrderItem polymorphic pivot so a single Order can carry
 * accessories, consumables, components, assets, and license seats
 * side-by-side.
 *
 * Explicitly NOT a purchase-order-workflow model — no state machine,
 * approvals, or receiving flow. This is just the "what was acquired,
 * when, from whom" record that the adjust-quantity flow and importers
 * write to today. A richer workflow (or a dedicated PurchaseOrder model)
 * can layer on later without disrupting this data.
 *
 * File attachments (receipt / invoice PDFs) are not modeled here yet —
 * follow-up if / when it's actually needed.
 */
class Order extends SnipeModel
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'orders';

    protected $fillable = [
        'order_number',
        'supplier_id',
        'company_id',
        'purchase_date',
        'created_by',
        'notes',
        'currency',
    ];

    protected $casts = [
        'purchase_date' => 'date',
    ];

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
