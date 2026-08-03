<?php

namespace App\Http\Traits;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustQuantityRequest;
use App\Http\Requests\UploadFileRequest;
use App\Models\Order;
use App\Models\OrderItem;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared body of the adjust-quantity controller action. Web and API
 * controllers for Accessory / Consumable / Component all run the same
 * sequence — authorize the update, save an optional receipt attachment,
 * resolve or create an Order + OrderItem for the requested acquisition,
 * call the AdjustsQuantity model trait with the Order's id, and
 * translate a DomainException (would-drop-below-in-use) into the shared
 * error string. Each controller still owns its own response shape
 * (redirect vs JSON) around that shared work.
 */
trait HandlesAdjustQuantity
{
    /**
     * Run the shared adjust-quantity work. Returns null on success or a
     * translated error string on failure, so the caller can wrap the
     * outcome in whichever response shape (RedirectResponse or
     * JsonResponse) is appropriate for the invoking controller.
     */
    protected function runAdjustQuantity(
        AdjustQuantityRequest $request,
        Model $model,
        string $storageKey,
    ): ?string {
        $this->authorize('update', $model);

        $filename = null;
        if ($request->hasFile('file')) {
            $filename = app(UploadFileRequest::class)->handleFile(
                Controller::getMapStoragePath()[$storageKey],
                Controller::getMapFilePrefix()[$storageKey].'-'.$model->id,
                $request->file('file'),
            );
        }

        $delta = (int) $request->input('amount');
        $orderId = $this->resolveOrderForAdjustment($request, $model, $delta);

        try {
            $model->adjustQuantity(
                $delta,
                $request->input('note'),
                $orderId,
                $filename,
            );
        } catch (DomainException) {
            return trans('general.adjust_quantity_below_zero');
        }

        return null;
    }

    /**
     * Find or create an Order from the request payload and append one
     * OrderItem line for the model / delta being adjusted. Returns the
     * Order's id (or null if the request carried no acquisition info,
     * e.g. an audit-only zero-delta submission with no order_number
     * given).
     *
     * Dedupes on (order_number, supplier_id, company_id) so multiple
     * adjust-quantity events that share those fields all land under a
     * single Order row. Never dedupes the OrderItem side — each
     * adjustment is its own line, matching the "one line per
     * acquisition event" semantic.
     *
     * Accepts the base Request rather than AdjustQuantityRequest
     * specifically so the legacy Api\{Accessory,Consumable,Component}
     * Controller::update paths — which run through ImageUploadRequest
     * for their qty-inside-PATCH shape — can call it with the same
     * shape as the dedicated adjust-quantity endpoint.
     */
    protected function resolveOrderForAdjustment(
        Request $request,
        Model $model,
        int $delta,
    ): ?int {
        $payload = $this->extractOrderPayloadFromRequest($request);

        if ($this->orderPayloadIsEmpty($payload)) {
            return null;
        }

        $order = Order::firstOrCreate(
            [
                'order_number' => $payload['order_number'],
                'supplier_id' => $payload['supplier_id'],
                'company_id' => $model->company_id ?? null,
            ],
            [
                'purchase_date' => $payload['purchase_date'],
                'currency' => $payload['currency'],
                'created_by' => auth()->id(),
            ],
        );

        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => $model::class,
            'item_id' => $model->id,
            // OrderItem.qty is always positive — a decrement adjustment
            // records the absolute number of units the line represents,
            // and the delta sign lives on the sibling action_log.
            'qty' => max(1, abs($delta)),
            'price' => $payload['unit_cost'],
        ]);

        return $order->id;
    }

    /**
     * Pull the five acquisition-metadata fields off the request and
     * normalize each into the shape Order::firstOrCreate wants. Split
     * from resolveOrderForAdjustment so the orchestrator stays small
     * and each field's read-and-normalize logic can move independently.
     *
     * @return array{order_number: ?string, supplier_id: ?int, purchase_date: ?string, unit_cost: ?float, currency: ?string}
     */
    private function extractOrderPayloadFromRequest(Request $request): array
    {
        $orderNumberRaw = trim((string) $request->input('order_number', ''));
        $currencyRaw = $request->filled('currency') ? trim((string) $request->input('currency')) : null;

        return [
            'order_number' => $orderNumberRaw !== '' ? $orderNumberRaw : null,
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null,
            'purchase_date' => $request->filled('purchase_date') ? $request->input('purchase_date') : null,
            'unit_cost' => $request->filled('unit_cost') ? (float) $request->input('unit_cost') : null,
            'currency' => ($currencyRaw !== null && $currencyRaw !== '') ? $currencyRaw : null,
        ];
    }

    /**
     * True when the request carried no acquisition context (all five
     * order-metadata fields blank). Audit-only submissions (zero delta
     * with no supplier / order number / date / cost / currency) fall
     * through here so we don't accrete meaningless Order rows for
     * pure inventory counts.
     *
     * @param  array{order_number: ?string, supplier_id: ?int, purchase_date: ?string, unit_cost: ?float, currency: ?string}  $payload
     */
    private function orderPayloadIsEmpty(array $payload): bool
    {
        return $payload['order_number'] === null
            && $payload['supplier_id'] === null
            && $payload['purchase_date'] === null
            && $payload['unit_cost'] === null
            && $payload['currency'] === null;
    }
}
