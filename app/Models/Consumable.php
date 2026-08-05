<?php

namespace App\Models;

use App\Models\Traits\Acceptable;
use App\Models\Traits\AdjustsQuantity;
use App\Models\Traits\CompanyableTrait;
use App\Models\Traits\HasOrders;
use App\Models\Traits\HasUploads;
use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use App\Presenters\ConsumablePresenter;
use App\Presenters\Presentable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Watson\Validating\ValidatingTrait;

class Consumable extends SnipeModel
{
    use HasFactory;

    protected $presenter = ConsumablePresenter::class;

    use Acceptable;
    use AdjustsQuantity;
    use CompanyableTrait;
    use HasOrders;
    use HasUploads;
    use Loggable, Presentable;
    use SoftDeletes;

    protected $table = 'consumables';

    protected $casts = [
        'purchase_date' => 'datetime',
        'requestable' => 'boolean',
        'category_id' => 'integer',
        'company_id' => 'integer',
        'supplier_id',
        'qty' => 'integer',
        'min_amt' => 'integer',
    ];

    /**
     * Category validation rules
     */
    public $rules = [
        'name' => 'required|max:255',
        'qty' => 'required|integer|min:0|max:99999',
        'category_id' => 'required|integer',
        'company_id' => 'integer|nullable|exists:companies,id|fmcs_company',
        'location_id' => 'exists:locations,id|nullable|fmcs_location',
        'min_amt' => 'integer|min:0|max:99999|nullable',
        'purchase_cost' => 'numeric|nullable|gte:0|max:99999999999999999.99',
        'purchase_date' => 'date_format:Y-m-d|nullable',
    ];

    /**
     * Whether the model should inject it's identifier to the unique
     * validation rules before attempting validation. If this property
     * is not set in the model it will default to true.
     *
     * @var bool
     */
    protected $injectUniqueIdentifier = true;

    use ValidatingTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'category_id',
        'company_id',
        'item_no',
        'location_id',
        'manufacturer_id',
        'supplier_id',
        'name',
        'model_number',
        'purchase_cost',
        'purchase_date',
        'qty',
        'min_amt',
        'requestable',
        'notes',
    ];

    use Searchable;

    /**
     * The attributes that should be included when searching the model.
     *
     * @var array
     */
    protected $searchableAttributes = [
        'name',
        'purchase_cost',
        'purchase_date',
        'item_no',
        'model_number',
        'notes',
    ];

    /**
     * The relations and their attributes that should be included when searching the model.
     *
     * @var array
     */
    protected $searchableRelations = [
        'category' => ['name'],
        'company' => ['name'],
        'location' => ['name'],
        'manufacturer' => ['name'],
        'supplier' => ['name'],
        'adminuser' => ['first_name', 'last_name', 'display_name'],
        // See Accessory::$searchableRelations. Search hits order_number
        // through the HasOrders trait's orders() HasManyThrough into
        // the Orders table so historical order references still match.
        'orders' => ['order_number'],
    ];

    /**
     * Sets the attribute of whether or not the consumable is requestable
     *
     * This isn't really implemented yet, as you can't currently request a consumable
     * however it will be implemented in the future, and we needed to include
     * this method here so all of our polymorphic methods don't break.
     *
     * @todo Update this comment once it's been implemented
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v3.0]
     *
     * @return Relation
     */
    public function setRequestableAttribute($value)
    {
        if ($value == '') {
            $value = null;
        }
        $this->attributes['requestable'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function isDeletable()
    {
        return Gate::allows('delete', $this)
            && ($this->numCheckedOut() === 0)
            && ($this->deleted_at == '');
    }

    /**
     * Establishes the component -> assignments relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v3.0]
     *
     * @return Relation
     */
    public function consumableAssignments()
    {
        return $this->hasMany(ConsumableAssignment::class);
    }

    public function percentRemaining()
    {
        if ($this->consumables_users_count == 0) {
            return 100;
        }

        if (($this->qty == '') || ($this->qty == 0)) {
            return 0;
        }

        return ($this->qty - $this->consumables_users_count) / $this->qty * 100;
    }

    /**
     * Establishes the component -> company relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v3.0]
     *
     * @return Relation
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Establishes the component -> manufacturer relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v3.0]
     *
     * @return Relation
     */
    public function manufacturer()
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    /**
     * Establishes the component -> location relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v3.0]
     *
     * @return Relation
     */
    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * Establishes the component -> category relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v3.0]
     *
     * @return Relation
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Establishes the component -> action logs relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v3.0]
     *
     * @return Relation
     */
    public function assetlog()
    {
        return $this->hasMany(Actionlog::class, 'item_id')->where('item_type', self::class)->orderBy('created_at', 'desc')->withTrashed();
    }

    /**
     * Gets the full image url for the consumable
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v3.0]
     *
     * @return string | false
     */
    public function getImageUrl($path = null)
    {
        // If there is a consumable image, use that
        if ($this->image) {
            return Storage::disk('public')->url(app('consumables_upload_path').$this->image);

            // Otherwise check for a category image
        } elseif (($this->category) && ($this->category->image)) {
            return Storage::disk('public')->url(app('categories_upload_path').e($this->category->image));
        }

        return false;
    }

    /**
     * Establishes the component -> users relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v3.0]
     */
    public function users(): Relation
    {
        return $this->belongsToMany(User::class, 'consumables_users', 'consumable_id', 'assigned_to')->withPivot('created_by')->withTrashed()->withTimestamps();
    }

    /**
     * Establishes the item -> supplier relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v6.1.1]
     *
     * @return Relation
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * Determine whether to send a checkin/checkout email based on
     * asset model category
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v4.0]
     *
     * @return bool
     */
    public function checkin_email()
    {
        return $this->category?->checkin_email;
    }

    /**
     * Determine whether this asset requires acceptance by the assigned user
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v4.0]
     *
     * @return bool
     */
    public function requireAcceptance()
    {
        return $this->category?->require_acceptance ?? false;
    }

    /**
     * Check how many items within a consumable are checked out
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v5.0]
     *
     * @return int
     */
    public function numCheckedOut()
    {
        return $this->consumables_users_count ?? $this->users()->count();
    }

    /**
     * AdjustsQuantity trait hook: units currently distributed to users.
     * The adjust-quantity modal uses this to reject decrements that
     * would leave the on-hand qty below what's already handed out.
     */
    public function currentlyInUseCount(): int
    {
        return (int) $this->numCheckedOut();
    }

    /**
     * Checks the number of available consumables
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v4.0]
     *
     * @return int
     */
    public function numRemaining()
    {
        $checkedout = $this->numCheckedOut();
        $total = $this->qty;
        $remaining = $total - $checkedout;

        return $remaining;
    }

    /**
     * Sum every OrderItem's line total (qty × price) grouped by the
     * parent Order's currency, so mixed-currency acquisitions render
     * as a per-currency breakdown instead of a single misleading total.
     *
     * Falls back to `qty × parent.purchase_cost` under the system's
     * default_currency when the item has no OrderItems yet (legacy
     * rows uncaught by backfill, or brand-new items with a purchase
     * cost set but no acquisitions recorded).
     *
     * Returns [] when both paths are empty. The info-panel skips the
     * "Total cost" line entirely in that case rather than showing 0.
     *
     * @return array<string, float> currency code => sum in that currency
     */
    public function totalCostSumByCurrency(): array
    {
        $lines = $this->orderItems()->with('order:id,currency')->get();

        $totals = $lines->reduce(function (array $carry, OrderItem $line) {
            if ($line->price === null) {
                return $carry;
            }
            $currency = $line->order?->currency
                ?? Setting::getSettings()?->default_currency
                ?? '';
            $carry[$currency] = ($carry[$currency] ?? 0) + ($line->qty * (float) $line->price);

            return $carry;
        }, []);

        // Account for units created before the Orders flow. Consumable
        // creation doesn't write an OrderItem (unlike Asset::created),
        // so the initial N units at parent.purchase_cost never land in
        // the OrderItem ledger. Add them here under location.currency
        // (or default_currency if the location has none) so this line's
        // currency matches how unit_cost is rendered in the info-panel.
        $allocatedQty = (int) $lines->sum('qty');
        $unaccountedQty = max(0, (int) $this->qty - $allocatedQty);
        if ($unaccountedQty > 0 && $this->purchase_cost !== null) {
            $fallbackCurrency = ($this->location && $this->location->currency !== '' && $this->location->currency !== null)
                ? $this->location->currency
                : (Setting::getSettings()?->default_currency ?? '');
            $totals[$fallbackCurrency] = ($totals[$fallbackCurrency] ?? 0) + ($unaccountedQty * (float) $this->purchase_cost);
        }

        return $totals;
    }

    /**
     * Naive cross-currency sum, kept for backwards compatibility with
     * external callers. New code should prefer totalCostSumByCurrency()
     * so mixed-currency totals stay disambiguated.
     */
    public function totalCostSum()
    {
        return array_sum($this->totalCostSumByCurrency()) ?: null;
    }

    /**
     * True when every recorded acquisition for this item came from the
     * same supplier. Info-panel supplier row hides when false so a
     * single supplier name doesn't misrepresent multi-supplier history.
     */
    public function hasConsistentSupplier(): bool
    {
        $orderSupplierIds = $this->orderItems()
            ->with('order:id,supplier_id')
            ->get()
            ->map(fn (OrderItem $line) => $line->order?->supplier_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $known = array_values(array_unique(array_filter(array_merge(
            [$this->supplier_id],
            $orderSupplierIds,
        ))));

        return count($known) <= 1;
    }

    /**
     * Get the list of checkouts for this consumable
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since  [v2.0]
     *
     * @return Relation
     */
    public function checkouts()
    {
        return $this->assetlog()->where('action_type', '=', 'checkout')
            ->orderBy('created_at', 'desc')
            ->withTrashed();
    }

    /**
     * -----------------------------------------------
     * BEGIN MUTATORS
     * -----------------------------------------------
     **/

    /**
     * This sets a value for qty if no value is given. The database does not allow this
     * field to be null, and in the other areas of the code, we set a default, but the importer
     * does not.
     *
     * This simply checks that there is a value for quantity, and if there isn't, set it to 0.
     *
     * @author A. Gianotto <snipe@snipe.net>
     *
     * @since  v6.3.4
     *
     * @return void
     */
    public function setQtyAttribute($value)
    {
        $this->attributes['qty'] = (! $value) ? 0 : intval($value);
    }

    /**
     * -----------------------------------------------
     * BEGIN QUERY SCOPES
     * -----------------------------------------------
     **/

    /**
     * Query builder scope to search on text filters for complex Bootstrap Tables API
     *
     * @param  Builder  $query  Query builder instance
     * @param  text  $filter  JSON array of search keys and terms
     * @return Builder Modified query builder
     */

    /**
     * Query builder scope to order on company
     *
     * @param  Builder  $query  Query builder instance
     * @param  string  $order  Order
     * @return Builder Modified query builder
     */
    public function scopeOrderCategory($query, $order)
    {
        return $query->join('categories', 'consumables.category_id', '=', 'categories.id')->orderBy('categories.name', $order);
    }

    /**
     * Query builder scope to order on location
     *
     * @param  Builder  $query  Query builder instance
     * @param  text  $order  Order
     * @return Builder Modified query builder
     */
    public function scopeOrderLocation($query, $order)
    {
        return $query->leftJoin('locations', 'consumables.location_id', '=', 'locations.id')->orderBy('locations.name', $order);
    }

    /**
     * Query builder scope to order on manufacturer
     *
     * @param  Builder  $query  Query builder instance
     * @param  string  $order  Order
     * @return Builder Modified query builder
     */
    public function scopeOrderManufacturer($query, $order)
    {
        return $query->leftJoin('manufacturers', 'consumables.manufacturer_id', '=', 'manufacturers.id')->orderBy('manufacturers.name', $order);
    }

    /**
     * Query builder scope to order on company
     *
     * @param  Builder  $query  Query builder instance
     * @param  string  $order  Order
     * @return Builder Modified query builder
     */
    public function scopeOrderCompany($query, $order)
    {
        return $query->leftJoin('companies', 'consumables.company_id', '=', 'companies.id')->orderBy('companies.name', $order);
    }

    /**
     * Query builder scope to order on remaining
     *
     * @param  Builder  $query  Query builder instance
     * @param  string  $order  Order
     * @return Builder Modified query builder
     */
    public function scopeOrderRemaining($query, $order)
    {
        $order_by = 'consumables.qty - consumables_users_count '.$order;

        return $query->orderByRaw($order_by);
    }

    /**
     * Query builder scope to order on supplier
     *
     * @param  Builder  $query  Query builder instance
     * @param  text  $order  Order
     * @return Builder Modified query builder
     */
    public function scopeOrderSupplier($query, $order)
    {
        return $query->leftJoin('suppliers', 'consumables.supplier_id', '=', 'suppliers.id')->orderBy('suppliers.name', $order);
    }

    public function scopeOrderByCreatedBy($query, $order)
    {
        return $query->leftJoin('users as users_sort', 'consumables.created_by', '=', 'users_sort.id')->select('consumables.*')->orderBy('users_sort.first_name', $order)->orderBy('users_sort.last_name', $order);
    }

    /**
     * Query builder scope to sort by the calculated `% remaining` column.
     *
     * Mirrors Consumable::percentRemaining(): (qty - consumables_users_count) / qty * 100.
     * consumables_users_count is added by withCount() in the API index()
     * before this scope runs. Guards against division by zero for
     * consumables with qty of 0.
     *
     * PostgreSQL note: references a SELECT-list alias inside a compound
     * ORDER BY expression, which PostgreSQL rejects per SQL standard.
     * Snipe-IT officially supports MySQL/MariaDB and tests on SQLite
     * (both allow this); moving to PostgreSQL would require inlining
     * the subquery or wrapping the query in an outer SELECT.
     */
    public function scopeOrderPercentRemaining($query, $order)
    {
        $direction = strtolower($order) === 'asc' ? 'asc' : 'desc';

        return $query->orderByRaw('CASE WHEN consumables.qty = 0 THEN 0 ELSE ((consumables.qty - consumables_users_count) * 100.0 / consumables.qty) END '.$direction);
    }
}
