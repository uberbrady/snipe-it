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
 * Shared body of the adjust-quantity controller action. Authorize,
 * save the optional receipt, resolve or create an Order + OrderItem,
 * call the AdjustsQuantity trait with the OrderItem id, turn
 * DomainException into the shared error string. Each controller wraps
 * the outcome in its own response shape (redirect or JSON).
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
        $orderItemId = $this->resolveOrderForAdjustment($request, $model, $delta);

        try {
            $model->adjustQuantity(
                $delta,
                $request->input('note'),
                $orderItemId,
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
     * OrderItem id (the parent Order is reachable via OrderItem->order),
     * or null when the request carried no acquisition info (audit-only
     * zero-delta with no order metadata).
     *
     * Dedupes the Order on (order_number, supplier_id, company_id).
     * Each adjustment gets its own OrderItem line so staggered receipts
     * under one order_number stay distinguishable.
     *
     * Accepts the base Request so the legacy Api\...Controller::update
     * paths (which use ImageUploadRequest) can call it the same way as
     * the dedicated adjust-quantity endpoint.
     */
    protected function resolveOrderForAdjustment(
        Request $request,
        Model $model,
        int $delta,
    ): ?int {
        // Orders / OrderItems record purchases only. A negative delta is
        // a correction / consumption / loss and a zero delta is a
        // physical-count audit — neither is a purchase, so neither
        // writes to the Orders ledger. Both still write an action_log
        // entry (that's the QuantityAdjust source of truth), just
        // without an order_item_id link.
        if ($delta <= 0) {
            return null;
        }

        $payload = $this->extractOrderPayloadFromRequest($request);

        if ($this->orderPayloadIsEmpty($payload)) {
            return null;
        }

        // Only dedupe when there's a real order_number label to match
        // on. A blank order_number is a distinct transaction each time
        // (own timestamp, supplier, cost, currency), not a bucket to
        // pool anonymous acquisitions into. created_by is set via
        // property assignment rather than mass-fill because it's
        // guarded on both Order and OrderItem to prevent forgery.
        if ($payload['order_number'] !== null) {
            $order = Order::firstOrNew(
                [
                    'order_number' => $payload['order_number'],
                    'supplier_id' => $payload['supplier_id'],
                    'company_id' => $model->company_id ?? null,
                ],
                [
                    'purchase_date' => $payload['purchase_date'],
                    'currency' => $payload['currency'],
                    'notes' => $payload['notes'],
                ],
            );
            if (! $order->exists) {
                $order->created_by = auth()->id();
                $order->save();
            }
        } else {
            $order = new Order([
                'order_number' => null,
                'supplier_id' => $payload['supplier_id'],
                'company_id' => $model->company_id ?? null,
                'purchase_date' => $payload['purchase_date'],
                'currency' => $payload['currency'],
                'notes' => $payload['notes'],
            ]);
            $order->created_by = auth()->id();
            $order->save();
        }

        $orderItem = new OrderItem([
            'order_id' => $order->id,
            'item_type' => $model::class,
            'item_id' => $model->id,
            // OrderItem.qty is always positive. The delta sign lives on
            // the sibling action_log.
            'qty' => max(1, abs($delta)),
            'price' => $payload['unit_cost'],
        ]);
        $orderItem->created_by = auth()->id();
        $orderItem->save();

        return $orderItem->id;
    }

    /**
     * Update the observer-created initial Order + OrderItem for a
     * freshly-saved inventory item with the form-supplied transaction
     * fields. None of these fields live on the accessory / consumable /
     * component parent column any more — they moved to Orders /
     * OrderItems, so the observer writes placeholders and the
     * controller enriches with the form values right after save.
     *
     * Order fields:    order_number, supplier_id, purchase_date, currency
     * OrderItem field: price (from request `purchase_cost`)
     *
     * No-op when the request carried none of them.
     */
    protected function enrichInitialOrderFromRequest(Request $request, Model $item): void
    {
        $orderNumber = trim((string) $request->input('order_number', ''));
        $currency = trim((string) $request->input('currency', ''));
        $supplierId = $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null;
        $purchaseDate = $request->filled('purchase_date') ? $request->input('purchase_date') : null;
        $purchaseCost = $request->filled('purchase_cost') ? (float) $request->input('purchase_cost') : null;
        // The create form doesn't have a per-order note field yet, but
        // accept `notes` if the caller (importer / API) passes one so the
        // initial Order carries acquisition context matching the modal.
        $notes = $request->filled('notes') ? trim((string) $request->input('notes')) : null;

        if ($orderNumber === '' && $currency === '' && $supplierId === null && $purchaseDate === null && $purchaseCost === null && $notes === null) {
            return;
        }

        $initialLine = $item->orderItems()->latest('id')->first();
        if (! $initialLine) {
            return;
        }
        $initialOrder = $initialLine->order;
        if (! $initialOrder) {
            return;
        }

        $orderUpdates = [];
        if ($orderNumber !== '' && $initialOrder->order_number !== $orderNumber) {
            $orderUpdates['order_number'] = $orderNumber;
        }
        if ($currency !== '' && $initialOrder->currency !== $currency) {
            $orderUpdates['currency'] = $currency;
        }
        if ($supplierId !== null && (int) $initialOrder->supplier_id !== $supplierId) {
            $orderUpdates['supplier_id'] = $supplierId;
        }
        if ($purchaseDate !== null && optional($initialOrder->purchase_date)->toDateString() !== $purchaseDate) {
            $orderUpdates['purchase_date'] = $purchaseDate;
        }
        if ($notes !== null && $initialOrder->notes !== $notes) {
            $orderUpdates['notes'] = $notes;
        }
        if ($orderUpdates !== []) {
            $initialOrder->update($orderUpdates);
        }

        if ($purchaseCost !== null && (float) $initialLine->price !== $purchaseCost) {
            $initialLine->update(['price' => $purchaseCost]);
        }
    }

    /**
     * Pull the acquisition-metadata fields off the request and
     * normalize each into the shape Order needs.
     *
     * @return array{order_number: ?string, supplier_id: ?int, purchase_date: ?string, unit_cost: ?float, currency: ?string}
     */
    private function extractOrderPayloadFromRequest(Request $request): array
    {
        $orderNumberRaw = trim((string) $request->input('order_number', ''));
        $currencyRaw = $request->filled('currency') ? trim((string) $request->input('currency')) : null;
        $noteRaw = $request->filled('note') ? trim((string) $request->input('note')) : null;

        return [
            'order_number' => $orderNumberRaw !== '' ? $orderNumberRaw : null,
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null,
            'purchase_date' => $request->filled('purchase_date') ? $request->input('purchase_date') : null,
            'unit_cost' => $request->filled('unit_cost') ? (float) $request->input('unit_cost') : null,
            'currency' => ($currencyRaw !== null && $currencyRaw !== '') ? $currencyRaw : null,
            'notes' => ($noteRaw !== null && $noteRaw !== '') ? $noteRaw : null,
        ];
    }

    /**
     * True when the request carried no acquisition context. Audit-only
     * zero-delta submissions with no order metadata fall through here
     * so we don't accrete meaningless Order rows for pure counts.
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
