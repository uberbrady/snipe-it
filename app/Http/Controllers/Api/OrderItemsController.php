<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Transformers\OrderItemsTransformer;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Standard REST endpoint for OrderItems. Backs the Orders tab on
 * accessory / consumable / component / model view pages, and any
 * external API consumer that needs to page / filter / sort acquisition
 * lines directly rather than through their parent inventory item.
 *
 * Filters:
 *   item_type       fully-qualified model class ("App\Models\Accessory")
 *   item_id         id of that item
 *   asset_model_id  aggregate: all OrderItems for every Asset of this model
 *
 * Sort covers OrderItem-native columns and cross-table columns
 * (order_number, purchase_date, supplier, currency) via a leftJoin
 * onto orders + suppliers. Search hits order_number, purchase_order,
 * and suppliers.name across the same joined query.
 */
class OrderItemsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Authorization: if the request narrows to a specific parent,
        // require view permission on THAT parent. Otherwise fall back
        // to the OrderItem-level policy so a global listing is gated
        // to superusers or the equivalent.
        $itemType = $request->input('item_type');
        $itemId = $request->input('item_id');
        $assetModelId = $request->input('asset_model_id');

        if ($assetModelId) {
            $this->authorize('view', AssetModel::findOrFail($assetModelId));
        } elseif ($itemType && $itemId && class_exists($itemType)) {
            $this->authorize('view', $itemType::findOrFail($itemId));
        } elseif (! auth()->user()?->isSuperUser()) {
            // Unfiltered "list every OrderItem" is a superuser-only
            // capability until an OrderPolicy exists.
            abort(403);
        }

        $query = OrderItem::query()
            ->with([
                'admin:id,first_name,last_name,username',
                'order:id,order_number,supplier_id,currency,purchase_date,created_by',
                'order.supplier:id,name',
                'order.admin:id,first_name,last_name,username',
            ]);

        if ($assetModelId) {
            $query->where('order_items.item_type', Asset::class)
                ->whereIn('order_items.item_id', Asset::where('model_id', $assetModelId)->select('id'));
        } elseif ($itemType && $itemId) {
            $query->where('order_items.item_type', $itemType)
                ->where('order_items.item_id', $itemId);
        }

        // leftJoin onto orders + suppliers so cross-table sort / search
        // can hit those columns. Select order_items.* so the model
        // hydrates cleanly and the joined columns don't leak into the
        // OrderItem attributes.
        $query->leftJoin('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'orders.supplier_id')
            ->select('order_items.*');

        if ($request->filled('search')) {
            $needle = '%'.$request->input('search').'%';
            $query->where(function ($q) use ($needle) {
                $q->where('orders.order_number', 'like', $needle)
                    ->orWhere('suppliers.name', 'like', $needle);
            });
        }

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        switch ($request->input('sort')) {
            case 'order_number':
                $query->orderBy('orders.order_number', $order);
                break;
            case 'purchase_date':
                $query->orderBy('orders.purchase_date', $order);
                break;
            case 'currency':
                $query->orderBy('orders.currency', $order);
                break;
            case 'supplier':
                $query->orderBy('suppliers.name', $order);
                break;
            case 'qty':
                $query->orderBy('order_items.qty', $order);
                break;
            case 'unit_cost':
                $query->orderBy('order_items.price', $order);
                break;
            case 'total_cost':
                // qty * price sort is computed rather than a stored column.
                $query->orderByRaw('(order_items.qty * COALESCE(order_items.price, 0)) '.$order);
                break;
            case 'created_at':
                $query->orderBy('order_items.created_at', $order);
                break;
            case 'created_by':
                $query->orderBy('order_items.created_by', $order);
                break;
            default:
                $query->orderBy('order_items.id', 'desc');
        }

        $total = (clone $query)->count();
        $offset = ($request->input('offset') > $total) ? $total : app('api_offset_value');
        $limit = app('api_limit_value');
        $lines = (clone $query)->skip($offset)->take($limit)->get();

        return response()->json(
            (new OrderItemsTransformer)->transformOrderItems($lines, $total),
            200,
            ['Content-Type' => 'application/json;charset=utf8'],
            JSON_UNESCAPED_UNICODE,
        );
    }
}
