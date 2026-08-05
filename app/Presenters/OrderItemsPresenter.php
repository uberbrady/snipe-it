<?php

namespace App\Presenters;

/**
 * Column layout for the Orders tab on Accessory / Consumable /
 * Component / AssetModel view pages. Rendered by <x-table> against
 * the corresponding /orders API endpoint per model.
 */
class OrderItemsPresenter
{
    public static function dataTableLayout(): string
    {
        return json_encode([
            [
                'field' => 'created_at',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.created_at'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'created_by',
                'scope' => 'col',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.created_by'),
                'visible' => true,
                'formatter' => 'usersLinkObjFormatter',
            ],
            [
                'field' => 'purchase_date',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.purchase_date'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'order_number',
                'scope' => 'col',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.order_number'),
                'visible' => true,
            ],
            [
                'field' => 'supplier',
                'scope' => 'col',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.supplier'),
                'visible' => true,
                'formatter' => 'suppliersLinkObjFormatter',
            ],
            [
                'field' => 'qty',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.qty'),
                'visible' => true,
                'footerFormatter' => 'sumFormatter',
                'class' => 'text-right text-padding-number-cell',
            ],
            [
                'field' => 'unit_cost',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.unit_cost'),
                'visible' => true,
                'footerFormatter' => 'sumFormatter',
                'class' => 'text-right text-padding-number-cell',
            ],
            [
                'field' => 'currency',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.currency'),
                'visible' => true,
            ],
            [
                'field' => 'total_cost',
                'scope' => 'col',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.total_cost'),
                'visible' => true,
                'footerFormatter' => 'sumFormatter',
                'class' => 'text-right text-padding-number-cell',
            ],
        ]);
    }
}
