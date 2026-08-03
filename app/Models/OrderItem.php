<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line on an Order. Polymorphic to the inventory model that was
 * acquired (Accessory / Consumable / Component / Asset / License) so a
 * single Order can span multiple item types. qty is the number of units
 * on this line; price is the per-unit cost (matches the semantic that
 * the existing purchase_cost columns use across the codebase).
 */
class OrderItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'item_type',
        'item_id',
        'qty',
        'price',
    ];

    protected $casts = [
        'qty' => 'integer',
        'price' => 'decimal:4',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): MorphTo
    {
        return $this->morphTo();
    }
}
