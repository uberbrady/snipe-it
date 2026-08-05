<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Acquisition record for inventory. One row per real-world order with
 * an optional order_number, supplier, purchase_date, currency, and
 * company scope. Line items (things acquired, quantities, prices) live
 * on the OrderItem polymorphic pivot so a single Order can carry
 * accessories, consumables, components, assets, and license seats
 * side by side.
 *
 * Explicitly NOT a purchase-order workflow (no state machine,
 * approvals, receiving). Just the "what was acquired, when, from
 * whom" record that the adjust-quantity flow, the AssetObserver, and
 * the CSV importers write to.
 *
 * File attachments (receipt / invoice PDFs) are not modeled here yet.
 */
class Order extends SnipeModel
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'orders';

    // NOTE: `created_by` is deliberately NOT fillable — accepting it via
    // mass-assignment would let a request attribute an Order to any
    // user_id the caller writes. All internal writers set it via
    // explicit `$order->created_by = auth()->id()`.
    protected $fillable = [
        'order_number',
        'supplier_id',
        'company_id',
        'purchase_date',
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

    /**
     * Currency to display for this order. Falls back to the system
     * default when the row's own `currency` column is null, so display
     * code doesn't have to reach for `$snipeSettings->default_currency`
     * itself. Returns null when neither is set (rare, but possible on
     * fresh installs with the default_currency setting unwritten).
     */
    public function displayCurrency(): ?string
    {
        if ($this->currency !== null && $this->currency !== '') {
            return $this->currency;
        }

        $default = Setting::getSettings()?->default_currency;

        return ($default !== null && $default !== '') ? $default : null;
    }
}
