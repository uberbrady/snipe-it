<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * SnipePermissionsPolicy provides methods for handling the granular permissions used throughout Snipe-IT.
 * Each "area" of a permission (which is usually a model, like Assets, Departments, etc), has a setting
 * in config/permissions.php like view/create/edit/delete (and sometimes some extra stuff like
 * checkout/checkin, etc.)
 *
 * A Policy should exist for each of these models, however if they only use the standard view/create/edit/delete,
 * the policy can be pretty simple, for example with just one method setting the column name:
 *
 * protected function columnName()
 * {
 *    return 'manufacturers';
 * }
 */
abstract class SnipePermissionsPolicy
{
    /**
     * This should return the key of the model in the users json permission string.
     *
     * @return bool
     */

    //
    abstract protected function columnName();

    use HandlesAuthorization;

    public function before(User $user, $ability, $item)
    {
        // Lets move all company related checks here.
        if ($item instanceof \App\Models\SnipeModel && ! Company::isCurrentUserHasAccess($item)) {
            return false;
        }
        \Log::debug("okay, we're still in the before() method, but the \$item is *not* an instance of SnipeModel. User: ".$user->username." Ability: $ability, Item's type is: ".gettype($item));
        // If an admin, they can do all asset related tasks.
        if ($user->hasAccess('admin')) {
            $settings = Setting::getSettings();
            \Log::debug("User has 'admin'. Is multi-company enabled? ".($settings && $settings->full_multiple_companies_support == 1 ? 'yes' : 'no')." does the company method exists? ". method_exists($item, 'company')." and is this a weird \$ability? :$ability. What is the item? ".print_r($item,true));

            if ($settings && $settings->full_multiple_companies_support == 1 && !method_exists($item, 'company') && !in_array($ability, ['view', 'index', 'viewRequestable'] )) {
                \Log::debug("Permission denied for 'admin'");
                return false; //Admin users *CANNOT* make any changes to cross-company things.
            }
            \Log::debug("Permission granted for 'admin'");
            return true;
        }
    }

    public function index(User $user)
    {
        return $user->hasAccess($this->columnName().'.view');
    }

    /**
     * Determine whether the user can view the $item.
     *
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function view(User $user, $item = null)
    {
        return $user->hasAccess($this->columnName().'.view');
    }

    public function files(User $user, $item = null)
    {
        return $user->hasAccess($this->columnName().'.files');
    }

    /**
     * Determine whether the user can create $items.
     *
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function create(User $user)
    {
        return $user->hasAccess($this->columnName().'.create');
    }

    /**
     * Determine whether the user can update the $item.
     *
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function update(User $user, $item = null)
    {
        return $user->hasAccess($this->columnName().'.edit');
    }


    /**
     * Determine whether the user can update the accessory.
     *
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function checkout(User $user, $item = null)
    {
        return $user->hasAccess($this->columnName().'.checkout');
    }

    /**
     * Determine whether the user can delete the $item.
     *
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function delete(User $user, $item = null)
    {
        $itemConditional = true;
        if ($item) {
            $itemConditional = empty($item->deleted_at);
        }

        return $itemConditional && $user->hasAccess($this->columnName().'.delete');
    }

    /**
     * Determine whether the user can manage the $item.
     *
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function manage(User $user, $item = null)
    {
        return $user->hasAccess($this->columnName().'.edit');
    }
}
