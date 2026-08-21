<?php

namespace App\Actions\CheckoutRequests;

use App\Exceptions\AssetNotRequestable;
use App\Exceptions\DuplicateCheckoutRequest;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\RequestAssetNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Log;

class CreateCheckoutRequestAction
{
    /**
     * @throws AssetNotRequestable
     * @throws AuthorizationException
     * @throws DuplicateCheckoutRequest
     */
    public static function run(Asset $asset, User $user): string
    {
        if (is_null(Asset::RequestableAssets()->find($asset->id))) {
            throw new AssetNotRequestable($asset);
        }
        if (! Company::isCurrentUserHasAccess($asset)) {
            throw new AuthorizationException;
        }

        // Enforce single-active-request-per-user-per-asset. Without
        // this gate the same POST fires repeatedly, adding duplicate
        // pending rows for the same (user, asset) pair. Downstream
        // aggregators (open_requests count, admin queue, requester
        // tab) all reason in terms of "one open row per pair" so
        // duplicates would double-count everywhere.
        if ($asset->isRequestedBy($user)) {
            throw new DuplicateCheckoutRequest;
        }

        $data['item'] = $asset;
        $data['target'] = $user;
        $data['item_quantity'] = 1;
        $settings = Setting::getSettings();

        $logaction = new Actionlog;
        $logaction->item_id = $data['asset_id'] = $asset->id;
        $logaction->item_type = $data['item_type'] = Asset::class;
        $logaction->created_at = $data['requested_date'] = date('Y-m-d H:i:s');
        $logaction->target_id = $data['user_id'] = auth()->id();
        $logaction->target_type = User::class;
        $logaction->location_id = $user->location_id ?? null;
        $logaction->logaction('requested');

        // Row write + counter increment share one transaction so a
        // partial failure can't leave the counter incremented without a
        // matching row (or vice versa). Counter is the denormalized
        // fast-path for open-request reads on the assets list; state
        // on the row is the source of truth, counter is a materialized
        // view kept in sync at write time.
        DB::transaction(function () use ($asset) {
            $asset->request();
            $asset->increment('requests_counter', 1);
        });

        try {
            $settings->notify((new RequestAssetNotification($data))->locale($settings->locale));
        } catch (\Exception $e) {
            Log::warning($e);
        }

        return true;
    }
}
