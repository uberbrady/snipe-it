<?php

namespace App\Http\Traits;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustQuantityRequest;
use App\Http\Requests\UploadFileRequest;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared body of the adjust-quantity controller action. Web and API
 * controllers for Accessory / Consumable / Component all run the same
 * sequence — authorize the update, save an optional receipt attachment,
 * call the AdjustsQuantity model trait, and translate a DomainException
 * (would-drop-below-in-use) into the shared error string. Each controller
 * still owns its own response shape (redirect vs JSON) around that
 * shared work.
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

        try {
            $model->adjustQuantity(
                (int) $request->input('amount'),
                $request->input('note'),
                $request->input('order_number'),
                $filename,
            );
        } catch (DomainException) {
            return trans('general.adjust_quantity_below_zero');
        }

        return null;
    }
}
