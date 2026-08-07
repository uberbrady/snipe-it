<?php

use App\Enums\ActionType;
use App\Models\Asset;
use App\Models\License;
use App\Models\OrderItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-shot reconciliation of parent `qty` against the action_logs
 * ledger for the three inventory models with qty adjustments
 * (Accessory, Consumable, Component). Runs the invariant:
 *
 *   parent.qty = SUM(action_logs.quantity WHERE item = parent
 *                    AND action_type IN ('create', 'qty_adjust'))
 *
 * Any row where the parent column disagrees with the ledger sum gets
 * corrected to the ledger value. The AdjustsQuantity trait wraps its
 * qty writes in a DB transaction so from this migration forward the
 * two stay in sync. This migration catches historical drift that
 * pre-dates the trait (direct `$model->qty = ...` writes that skipped
 * the log).
 *
 * License is deliberately excluded. License.seats reconciliation
 * would also need to create or destroy LicenseSeat pivot rows to keep
 * the seat-tracking invariants, which is beyond a data-only fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Filter OrderItem::ITEM_TYPES down to the models with a scalar
        // `qty` column that this reconciliation actually operates on.
        // Asset is 1:1 (no qty column, one row per unit), and License
        // is excluded per the note above.
        $qtyModels = array_diff(OrderItem::ITEM_TYPES, [Asset::class, License::class]);

        foreach ($qtyModels as $modelClass) {
            $this->reconcileFor($modelClass);
        }
    }

    public function down(): void
    {
        // No-op. We can't restore whatever drift existed before the
        // reconciliation because the ledger IS the correct value.
    }

    private function reconcileFor(string $modelClass): void
    {
        $create = ActionType::Create->value;
        $qtyAdjust = ActionType::QuantityAdjust->value;

        $modelClass::query()->chunkById(500, function ($rows) use ($modelClass, $create, $qtyAdjust) {
            foreach ($rows as $model) {
                $expected = (int) DB::table('action_logs')
                    ->where('item_type', $modelClass)
                    ->where('item_id', $model->id)
                    ->whereIn('action_type', [$create, $qtyAdjust])
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                $actual = (int) $model->qty;

                if ($expected === $actual) {
                    continue;
                }

                // Legacy-data safety: pre-replenish deployments never
                // populated action_logs.quantity for `create` events
                // (the `qty_adjust` action_type didn't exist), so the
                // ledger sum comes out at 0 or a stray small value on
                // every legacy row. Overwriting the real qty with that
                // stray value drives it below the currently-in-use
                // count and renders "-N Remaining" in the UI. Skip
                // when the ledger has no meaningful entries or when
                // the sum would push qty below in-use.
                if ($expected === 0 && $actual > 0) {
                    continue;
                }

                $inUse = method_exists($model, 'currentlyInUseCount')
                    ? (int) $model->currentlyInUseCount()
                    : 0;

                if ($expected < $inUse) {
                    Log::info(sprintf(
                        'Skipping reconcile of %s#%d: ledger sum %d < in-use count %d (probably stale pre-replenish action_logs)',
                        $modelClass,
                        $model->id,
                        $expected,
                        $inUse,
                    ));

                    continue;
                }

                Log::info(sprintf(
                    'Reconciling %s#%d qty: %d -> %d (ledger sum)',
                    $modelClass,
                    $model->id,
                    $actual,
                    $expected,
                ));

                // Direct DB update to bypass observers and events. The
                // AdjustsQuantity trait would try to write another
                // action_log entry, which would defeat the "sum equals
                // the ledger" invariant we just calculated.
                DB::table((new $modelClass)->getTable())
                    ->where('id', $model->id)
                    ->update(['qty' => $expected]);
            }
        });
    }
};
