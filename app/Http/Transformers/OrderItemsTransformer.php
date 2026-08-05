<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Collection;

class OrderItemsTransformer
{
    public function transformOrderItems(Collection $lines, $total): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = $this->transformOrderItem($line);
        }

        return (new DatatablesTransformer)->transformDatatables($rows, $total);
    }

    public function transformOrderItem(OrderItem $line): array
    {
        $order = $line->order;
        $qty = (int) $line->qty;
        $price = $line->price !== null ? (float) $line->price : null;

        return [
            'id' => (int) $line->id,
            'order_number' => $order?->order_number ? e($order->order_number) : null,
            'supplier' => $order?->supplier ? [
                'id' => (int) $order->supplier->id,
                'name' => e($order->supplier->name),
            ] : null,
            'purchase_date' => Helper::getFormattedDateObject($order?->purchase_date, 'date'),
            'qty' => $qty,
            'unit_cost' => $price !== null ? Helper::formatCurrencyOutput($price) : null,
            'currency' => $order?->currency ? e($order->currency) : null,
            'total_cost' => $price !== null ? Helper::formatCurrencyOutput($qty * $price) : null,
            'notes' => $order?->notes ? Helper::parseEscapedMarkedownInline($order->notes) : null,
            // Prefer the OrderItem's own creator (per-line authorship).
            // Falls back to the parent Order's created_by so pre-146000
            // rows with a null order_items.created_by still show a name.
            'created_by' => ($line->admin ?? $order?->admin) ? [
                'id' => (int) ($line->admin?->id ?? $order->admin->id),
                'name' => e(($line->admin ?? $order->admin)->display_name),
            ] : null,
            'created_at' => Helper::getFormattedDateObject($line->created_at, 'datetime'),
        ];
    }
}
