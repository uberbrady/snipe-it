<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = ['action_log_id', 'order_number', 'supplier_id', 'purchase_date', 'purchase_cost'];

    public function actionlog()
    {
        return $this->belongsTo(ActionLog::class, 'action_log_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
