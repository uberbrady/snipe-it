<?php

namespace App\Models\Traits;

use App\Enums\ActionType;
use App\Models\Actionlog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Adds a shared "adjust the on-hand quantity by N" API to
 * inventory-style models (Accessory, Consumable, Component, License).
 *
 * The consuming model tells the trait which integer column represents
 * its on-hand count via getAdjustableQuantityColumn() — qty for
 * accessories/consumables/components, seats for licenses.
 *
 * Every adjustment writes one ActionType::QuantityAdjust entry to
 * action_logs with:
 *   - quantity = signed delta (positive for replenish, negative for decrement)
 *   - note = the operator's reason (required by the controller)
 *   - order_number = optional PO / order reference
 *
 * License overrides adjustQuantity() so that adding or removing
 * seats also creates or destroys the matching LicenseSeat pivot rows.
 */
trait AdjustsQuantity
{
    /**
     * Which integer column on this model represents its on-hand
     * quantity. Default matches accessories / consumables / components.
     */
    public function getAdjustableQuantityColumn(): string
    {
        return 'qty';
    }

    /**
     * How many units of this item are currently checked out / in use
     * (accessory checkouts, consumable distributions, component ->asset
     * assignments). Models override; default 0 means "nothing in use"
     * and only zero is a hard floor.
     */
    public function currentlyInUseCount(): int
    {
        return 0;
    }

    /**
     * Apply a signed delta to the on-hand quantity and log the change.
     *
     * Uses Eloquent's increment/decrement so the write is atomic against
     * concurrent adjusters (SQL UPDATE ... SET qty = qty + ?, not a
     * PHP-side read-modify-write). Wrapped in a transaction so the log
     * entry and the quantity change either both happen or both roll back.
     *
     * Rejects any adjustment that would leave the on-hand quantity below
     * the number of units currently in use — decrementing below what's
     * already checked out to users/assets would leave the DB inconsistent
     * (checkouts pointing at inventory that supposedly doesn't exist).
     *
     * @throws DomainException when the resulting quantity would go
     *                         below the in-use count. Controllers should
     *                         convert this to a user-visible flash error.
     *                         Uses DomainException specifically so callers
     *                         don't accidentally swallow QueryException
     *                         (which extends RuntimeException) as a
     *                         below-floor violation.
     */
    public function adjustQuantity(int $delta, string $note, ?string $orderNumber = null, ?string $filename = null): void
    {
        if ($delta === 0) {
            return;
        }

        $column = $this->getAdjustableQuantityColumn();
        $current = (int) ($this->{$column} ?? 0);
        $inUse = max(0, (int) $this->currentlyInUseCount());
        $floor = $inUse;

        if ($current + $delta < $floor) {
            throw new DomainException(
                "Adjustment would take on-hand quantity ({$current}) below the {$inUse} unit(s) currently in use (delta={$delta})"
            );
        }

        DB::transaction(function () use ($delta, $column, $note, $orderNumber, $filename) {
            // Use the query builder directly (not $this->increment) so
            // the model's `updated` event doesn't fire. Firing it would
            // write a second "update" action_log entry (with log_meta of
            // {qty:{old,new}}) alongside our QuantityAdjust log. Keep
            // the in-memory attribute in sync so any downstream code
            // reading $this->qty after the call sees the new value.
            $delta > 0
                ? $this->newQuery()->where('id', $this->id)->increment($column, $delta)
                : $this->newQuery()->where('id', $this->id)->decrement($column, abs($delta));

            $this->{$column} = (int) $this->{$column} + $delta;
            $this->syncOriginalAttribute($column);

            $log = new Actionlog;
            $log->item_type = static::class;
            $log->item_id = $this->id;
            $log->created_by = auth()->id();
            $log->note = $note;
            $log->order_number = $orderNumber;
            $log->quantity = $delta;
            // Receipt/invoice attaches to the same log row rather than a
            // separate 'uploaded' entry, so the history table shows one
            // consolidated line per replenishment. The caller handles the
            // actual file save via UploadFileRequest::handleFile and just
            // passes the returned filename through.
            $log->filename = $filename;
            $log->logaction(ActionType::QuantityAdjust->value);
        });
    }
}
