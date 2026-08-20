<?php

namespace App\Presenters;

/**
 * bs-table column layout for the admin /hardware/requested view.
 * Pairs with GET /api/v1/requests + CheckoutRequestsTransformer, so
 * the internal admin UI eats the same API surface external integrators
 * consume for #15541.
 */
class CheckoutRequestPresenter extends Presenter
{
    /**
     * @return string
     */
    public static function dataTableLayout()
    {
        $layout = [
            [
                // Selection column for the bulk-cancel action. Rows
                // gate on available_actions.bulk_selectable.cancel via
                // the standard checkboxEnabledFormatter, so a request
                // that's already been canceled between "load table"
                // and "click Go" simply cannot be selected.
                'field' => 'checkbox',
                'scope' => 'col',
                'checkbox' => true,
                'formatter' => 'checkboxEnabledFormatter',
                'titleTooltip' => trans('general.select_all_none'),
                'printIgnore' => true,
                'class' => 'hidden-print',
            ], [
                'field' => 'requestable.image',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'visible' => true,
                'title' => trans('general.image'),
                'formatter' => 'requestableImageFormatter',
            ], [
                'field' => 'requestable.name',
                'scope' => 'col',
                'searchable' => true,
                'sortable' => false,
                'title' => trans('general.name'),
                'visible' => true,
                'formatter' => 'requestableNameFormatter',
            ], [
                'field' => 'requestable.location.name',
                'scope' => 'col',
                'searchable' => true,
                'sortable' => false,
                'title' => trans('general.location'),
                'visible' => true,
            ], [
                'field' => 'requestable.expected_checkin',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => false,
                'title' => trans('admin/hardware/form.expected_checkin'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ], [
                'field' => 'user',
                'scope' => 'col',
                'searchable' => true,
                'sortable' => false,
                'title' => trans('admin/hardware/table.requesting_user'),
                'visible' => true,
                'formatter' => 'requesterFormatter',
            ], [
                // Compact list of every open requester for THIS row's
                // requestable, current row included. Reuses the same
                // "1 / 2-3 / first + (+N more) with tooltip" pattern
                // as the accessory/consumable/component orders column.
                'field' => 'pending_requesters',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'visible' => true,
                'title' => trans('admin/hardware/table.pending_requesters'),
                'formatter' => 'ordersSummaryFormatter',
            ], [
                'field' => 'requested_at',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => true,
                'title' => trans('admin/hardware/table.requested_date'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ], [
                'field' => 'actions',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => false,
                'switchable' => false,
                'title' => trans('button.actions'),
                'visible' => true,
                'formatter' => 'checkoutRequestActionsFormatter',
                'printIgnore' => true,
                'class' => 'hidden-print',
            ],
        ];

        return json_encode($layout);
    }
}
