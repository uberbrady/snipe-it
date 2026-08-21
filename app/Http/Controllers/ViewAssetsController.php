<?php

namespace App\Http\Controllers;

use App\Actions\CheckoutRequests\CancelCheckoutRequestAction;
use App\Actions\CheckoutRequests\CreateCheckoutRequestAction;
use App\Enums\ActionType;
use App\Exceptions\AssetNotRequestable;
use App\Models\Accessory;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\License;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\RequestAssetCancelation;
use App\Notifications\RequestAssetNotification;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * This controller handles all actions related to the ability for users
 * to view their own assets in the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 */
class ViewAssetsController extends Controller
{
    /**
     * Extract custom fields that should be displayed in user view.
     */
    private function extractCustomFields(User $user): array
    {
        $fieldArray = [];
        foreach ($user->assets as $asset) {
            if ($asset->model && $asset->model->fieldset) {
                foreach ($asset->model->fieldset->fields as $field) {
                    if ($field->display_in_user_view == '1') {
                        $fieldArray[$field->db_column] = $field->name;
                    }
                }
            }
        }

        return array_unique($fieldArray);
    }

    /**
     * Get list of users viewable by the current user.
     */
    private function getViewableUsers(User $authUser): Collection
    {
        // SuperAdmin sees all users
        if ($authUser->isSuperUser()) {
            return User::select('id', 'first_name', 'last_name', 'username')
                ->where('activated', 1)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();
        }

        // Regular manager sees only their subordinates + self
        $managedUsers = $authUser->getAllSubordinates();

        // If user has subordinates, show them with self at beginning
        if ($managedUsers->count() > 0) {
            return collect([$authUser])->merge($managedUsers)
                ->sortBy('last_name')
                ->sortBy('first_name');
        }

        // User has no subordinates, only sees themselves
        return collect([$authUser]);
    }

    /**
     * Get the selected user ID from request or default to current user.
     */
    private function getSelectedUserId(Request $request, Collection $subordinates, int $defaultUserId): int
    {
        // If no subordinates or no user_id in request, return default
        if ($subordinates->count() <= 1 || ! $request->filled('user_id')) {
            return $defaultUserId;
        }

        $requestedUserId = (int) $request->input('user_id');

        // Validate if the requested user is allowed
        if ($subordinates->contains('id', $requestedUserId)) {
            return $requestedUserId;
        }

        // If invalid ID or not authorized, return default
        return $defaultUserId;
    }

    /**
     * Show user's assigned assets with optional manager view functionality.
     */
    public function getIndex(Request $request): View|RedirectResponse
    {
        $authUser = auth()->user();
        $settings = Setting::getSettings();
        $subordinates = collect();
        $selectedUserId = $authUser->id;

        // Process manager view if enabled
        if ($settings->manager_view_enabled) {
            $subordinates = $this->getViewableUsers($authUser);
            $selectedUserId = $this->getSelectedUserId($request, $subordinates, $authUser->id);
        }

        // Load the data for the user to be viewed (either auth user or selected subordinate)
        $userToView = User::with([
            'assets',
            'assets.model',
            'assets.model.fieldset.fields',
            'consumables',
            'accessories',
            'licenses',
            'companies',
        ])->find($selectedUserId);

        // If the user to view couldn't be found (shouldn't happen with proper logic), redirect with error
        if (! $userToView) {
            return redirect()->route('view-assets')->with('error', trans('admin/users/message.user_not_found'));
        }

        // Process custom fields for the user being viewed
        $fieldArray = $this->extractCustomFields($userToView);

        // Pass the necessary data to the view
        return view('account/view-assets', [
            'user' => $userToView, // Use 'user' for compatibility with the existing view
            'field_array' => $fieldArray,
            'settings' => $settings,
            'subordinates' => $subordinates,
            'selectedUserId' => $selectedUserId,
        ]);
    }

    /**
     * Returns view of requestable items for a user.
     */
    public function getRequestableIndex(): View
    {
        // Every tab on /account/requestable is API-backed now, so the
        // server-rendered page is a pure shell. All the controller
        // needs is the reachable-count per type - drives the tab
        // badge + "is this tab visible at all" gate. The API endpoint
        // that hydrates each tab re-applies the same
        // Requestable<Foo>() scope + CompanyableTrait global scope
        // filters, so the counts here stay consistent with what the
        // AJAX call will render.
        $counts = [
            'assets' => Asset::Hardware()->RequestableAssets()->count(),
            'models' => AssetModel::RequestableModels()->count(),
            'accessories' => Accessory::RequestableAccessories()->count(),
            'consumables' => Consumable::RequestableConsumables()->count(),
            'components' => Component::RequestableComponents()->count(),
            'licenses' => License::RequestableLicenses()->count(),
        ];

        // First tab with a non-zero reachable-count wins the active
        // class. Null when every tab is empty; the view short-
        // circuits to an empty-state alert in that case.
        $activeTab = null;
        foreach (['assets', 'models', 'accessories', 'consumables', 'components', 'licenses'] as $tab) {
            if ($counts[$tab] > 0) {
                $activeTab = $tab;
                break;
            }
        }

        return view('account/requestable-assets', compact('counts', 'activeTab'));
    }

    public function getRequestItem(Request $request, $itemType, $itemId = null, $requestingUser = null): RedirectResponse
    {
        // Cancel-by-admin is inferred from the presence of a
        // requestingUser segment that doesn't match the caller.
        // The old URL shape carried a trusted `cancel_by_admin`
        // flag, which was safe (server-side is_admin re-check
        // blocked forgery) but redundant now that the same
        // information falls out of comparing $requestingUser to
        // the auth id.
        $cancel_by_admin = $requestingUser !== null
            && (int) $requestingUser !== (int) auth()->id();
        $data = [];
        $item = null;
        $fullItemType = 'App\\Models\\'.studly_case($itemType);

        if ($itemType == 'asset_model') {
            $itemType = 'model';
        }
        $item = call_user_func([$fullItemType, 'find'], $itemId);

        // Null $item covers two cases: a straight bad id, and a
        // cross-company id that the CompanyableTrait's global scope
        // hid from the current caller. Either way, return the same
        // "not requestable" error so we don't leak the existence of
        // items in other companies AND don't 500 on the null-deref
        // that would otherwise land two lines below.
        if ($item === null) {
            return redirect()->back()->with('error', trans('admin/hardware/message.requests.error'));
        }

        $user = auth()->user();

        $logaction = new Actionlog;
        $logaction->item_id = $data['asset_id'] = $item->id;
        $logaction->item_type = $fullItemType;
        $logaction->created_at = $data['requested_date'] = date('Y-m-d H:i:s');

        if ($user->location_id) {
            $logaction->location_id = $user->location_id;
        }

        $logaction->target_id = $data['user_id'] = auth()->id();
        $logaction->target_type = User::class;

        $data['item_quantity'] = $request->has('request-quantity') ? e($request->input('request-quantity')) : 1;
        $data['requested_by'] = $user->display_name;
        $data['item'] = $item;
        $data['item_type'] = $itemType;
        $data['target'] = auth()->user();

        $data['item_url'] = match ($fullItemType) {
            Asset::class => route('hardware.show', $item->id),
            AssetModel::class => route('view/model', $item->id),
            Accessory::class => route('accessories.show', $item->id),
            Consumable::class => route('consumables.show', $item->id),
            Component::class => route('components.show', $item->id),
            License::class => route('licenses.show', $item->id),
            default => route("view/{$itemType}", $item->id),
        };

        $settings = Setting::getSettings();

        $is_admin = $user->isSuperUser() || $user->isAdmin();

        if ($cancel_by_admin && ! $is_admin) {
            return redirect()->back()->with('error', trans('general.insufficient_permissions'));
        }

        if (($item_request = $item->isRequestedBy($user)) || ($is_admin && $cancel_by_admin)) {
            $item->cancelRequest($is_admin && $cancel_by_admin ? $requestingUser : null);
            $data['item_quantity'] = ($item_request) ? $item_request->quantity : 1;
            // Surface the reservation window + notes (if any) on the
            // cancel notification so the admin knows exactly which
            // slot was scrapped and what the requester was originally
            // asking for. Only the row we found survives cancellation
            // long enough for us to read here (cancelRequest above
            // sets canceled_at but leaves the other fields intact).
            if ($item_request) {
                $data['start_date'] = $item_request->start_date;
                $data['end_date'] = $item_request->end_date;
                $data['note'] = $item_request->notes;
            }
            $logaction->logaction(ActionType::RequestCanceled);

            if (($settings->alert_email != '') && ($settings->alerts_enabled == '1') && (! config('app.lock_passwords'))) {
                try {
                    $settings->notify((new RequestAssetCancelation($data))->locale($settings->locale));
                } catch (Exception $e) {
                    Log::warning('Could not send request cancellation notification: '.$e->getMessage());
                }
            }

            // Prefer an explicit active_tab hint over a plain back()
            // so the requester lands on the tab they submitted from;
            // fall back to back() for callers that don't carry the
            // hint (e.g. admin cancel from /requests).
            if ($tab = $this->safeActiveTab($request->input('active_tab'))) {
                return redirect()->route('account.requestable')
                    ->withFragment($tab)
                    ->with('success', trans('admin/hardware/message.requests.canceled'));
            }

            return redirect()->back()->with('success', trans('admin/hardware/message.requests.canceled'));
        } else {
            // AssetModel was previously missing from this gate, so a
            // POST to /account/request/asset_model/{id} would bypass
            // the model's `requestable` flag entirely and still
            // create a request record. Consumable and Component both
            // gained the Requestable trait alongside a per-row
            // `requestable` flag; extending the gate here keeps the
            // "must be flagged requestable" rule symmetric across
            // every request-eligible type. The Requestable*() scopes
            // rely on the model's global CompanyableScope, so FMCS
            // (and location-scoping when enabled) apply automatically.
            if (($fullItemType === Asset::class && is_null(Asset::RequestableAssets()->find($item->id)))
                || ($fullItemType === Accessory::class && is_null(Accessory::RequestableAccessories()->find($item->id)))
                || ($fullItemType === AssetModel::class && is_null(AssetModel::RequestableModels()->find($item->id)))
                || ($fullItemType === Consumable::class && is_null(Consumable::RequestableConsumables()->find($item->id)))
                || ($fullItemType === Component::class && is_null(Component::RequestableComponents()->find($item->id)))
                || ($fullItemType === License::class && is_null(License::RequestableLicenses()->find($item->id)))) {
                return redirect()->back()->with('error', trans('admin/hardware/message.requests.error'));
            }

            // Optional reservation window. Empty strings from the
            // request-modal date pickers coerce to null so an "I need
            // this whenever" request doesn't accidentally persist
            // today's date as the start. Uses Laravel's built-in
            // validation strings so a bad range gets the framework's
            // localized "after or equal to" message rather than a
            // hand-rolled key we'd have to translate ourselves.
            $reservationValidator = validator($request->only(['start_date', 'end_date']), [
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);
            if ($reservationValidator->fails()) {
                return redirect()->back()->withInput()->withErrors($reservationValidator);
            }
            $startDate = $request->filled('start_date') ? $request->input('start_date') : null;
            $endDate = $request->filled('end_date') ? $request->input('end_date') : null;
            // Optional free-text notes the requester attaches to
            // explain what they need. Empty string coerces to null so
            // an untouched textarea doesn't persist a blank row and
            // then leak an empty "Additional Notes" block into the
            // admin's mail.
            $notes = $request->filled('notes') ? $request->input('notes') : null;

            // Also feed the notification, so admins see the window
            // right in the "new request" email/slack. Blank values
            // stay out of the mail per the template's isset+non-empty
            // guards. `note` (singular) is the existing template key
            // for the additional-notes row; reusing it means the mail
            // + slack layouts pick this up without changes.
            $data['start_date'] = $startDate;
            $data['end_date'] = $endDate;
            $data['note'] = $notes;

            $item->request($data['item_quantity'], $startDate, $endDate, $notes);
            if (($settings->alert_email != '') && ($settings->alerts_enabled == '1') && (! config('app.lock_passwords'))) {
                $logaction->logaction('requested');
                try {
                    $settings->notify((new RequestAssetNotification($data))->locale($settings->locale));
                } catch (Exception $e) {
                    Log::warning('Could not send asset request notification: '.$e->getMessage());
                }
            }

            $redirect = redirect()->route('account.requestable')
                ->with('success', trans('admin/hardware/message.requests.success'));
            if ($tab = $this->safeActiveTab($request->input('active_tab'))) {
                $redirect->withFragment($tab);
            }

            return $redirect;
        }
    }

    /**
     * Process a specific requested asset
     *
     * @param  null  $assetId
     */
    public function store(Request $request, Asset $asset): RedirectResponse
    {
        try {
            CreateCheckoutRequestAction::run($asset, auth()->user());

            $redirect = redirect()->route('account.requestable')
                ->with('success', trans('admin/hardware/message.requests.success'));
            if ($tab = $this->safeActiveTab($request->input('active_tab'))) {
                $redirect->withFragment($tab);
            }

            return $redirect;
        } catch (AssetNotRequestable $e) {
            return redirect()->back()->with('error', 'Asset is not requestable');
        } catch (\App\Exceptions\DuplicateCheckoutRequest $e) {
            return redirect()->back()->with('error', trans('admin/hardware/message.requests.duplicate'));
        } catch (AuthorizationException $e) {
            return redirect()->back()->with('error', trans('admin/hardware/message.requests.error'));
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->with('error', trans('general.something_went_wrong'));
        }
    }

    public function destroy(Request $request, Asset $asset): RedirectResponse
    {
        try {
            CancelCheckoutRequestAction::run($asset, auth()->user());

            $redirect = redirect()->route('account.requestable')
                ->with('success', trans('admin/hardware/message.requests.canceled'));
            if ($tab = $this->safeActiveTab($request->input('active_tab'))) {
                $redirect->withFragment($tab);
            }

            return $redirect;
        } catch (\App\Exceptions\NoActiveCheckoutRequest $e) {
            return redirect()->back()->with('error', trans('admin/hardware/message.requests.no_active'));
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->with('error', trans('general.something_went_wrong'));
        }
    }

    public function getRequestedAssets(): View
    {
        return view('account/requested');
    }

    /**
     * Validate the client-supplied `active_tab` hint against the
     * known tab ids on /account/requestable. Returns the tab
     * id when it's on the allowlist, null otherwise. Bootstrap 3
     * doesn't restore tab state on a full-page navigation, so the
     * caller passes this into RedirectResponse::withFragment() plus
     * a small hash->tab activator on the destination view keeps
     * the requester on the tab they submitted from. Only known tab
     * ids are honored so an arbitrary `active_tab` value can't
     * inject something unexpected into the redirect fragment.
     */
    private function safeActiveTab(?string $tab): ?string
    {
        $allowed = ['assets', 'models', 'accessories', 'consumables', 'components', 'licenses'];

        return in_array($tab, $allowed, true) ? $tab : null;
    }
}
