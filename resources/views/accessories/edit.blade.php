@extends('layouts/edit-form', [
    'createText' => trans('admin/accessories/general.create') ,
    'updateText' => trans('admin/accessories/general.update'),
    'helpPosition'  => 'right',
    'helpText' => trans('help.accessories'),
    'formAction' => (isset($item->id)) ? route('accessories.update', ['accessory' => $item->id]) : route('accessories.store'),
    'index_route' => 'accessories.index',
    'options' => [
                'index' => trans('admin/hardware/form.redirect_to_all', ['type' => 'accessories']),
                'item' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.accessory')]),
               ]
])

{{-- Page content --}}
@section('inputFields')

@include ('partials.forms.edit.company-select', ['translated_name' => trans('general.company'), 'fieldname' => 'company_id'])
@include ('partials.forms.edit.name', ['translated_name' => trans('admin/accessories/general.accessory_name')])
@include ('partials.forms.edit.category-select', ['translated_name' => trans('general.category'), 'fieldname' => 'category_id', 'required' => 'true','category_type' => 'accessory'])
@if(!isset($item->id))
@include ('partials.forms.edit.supplier-select', ['translated_name' => trans('general.supplier'), 'fieldname' => 'supplier_id'])
@endif
@include ('partials.forms.edit.manufacturer-select', ['translated_name' => trans('general.manufacturer'), 'fieldname' => 'manufacturer_id'])
@include ('partials.forms.edit.location-select', ['translated_name' => trans('general.location'), 'fieldname' => 'location_id'])
@include ('partials.forms.edit.model_number')
@if(!isset($item->id))
@include ('partials.forms.edit.order_number')
@include ('partials.forms.edit.purchase_date')
@include ('partials.forms.edit.purchase_cost', ['currency_type' => $item->location->currency ?? null])
@include ('partials.forms.edit.quantity')
@else
    {{-- FIXME - this looks TERRIBLE --}}
    <a href="#" style="margin-right:5px;"
       class="btn btn-warning btn-sm btn-social btn-block hidden-print quantity-adjustor"
       data-toggle="modal"
       data-target="#adjust-quantity-modal">
        <x-icon type="plus-minus"/>
        {{ trans('general.adjust_quantity') }}
    </a>
    <x-adjust-quantity-modal :target="route('api.accessories.adjust',$item->id)"
                             :quantity=" $item->numRemaining()"/>
@endif
@include ('partials.forms.edit.minimum_quantity')
@include ('partials.forms.edit.notes')
@include ('partials.forms.edit.image-upload', ['image_path' => app('accessories_upload_path')])


@stop
