@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/hardware/general.checkin') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

    <style>
        .input-group {
            padding-left: 0px !important;
        }
    </style>

    <x-container class="col-md-7 col-sm-11 col-xs-12 col-md-offset-2">
        <x-form :route="$backto == 'user'
            ? route('hardware.checkin.store', ['assetId' => $asset->id, 'backto' => 'user'])
            : route('hardware.checkin.store', ['assetId' => $asset->id])">

            <x-box>
                <x-slot:header>
                    {{ trans('admin/hardware/form.tag') }} {{ $asset->asset_tag }}
                </x-slot:header>

                <div class="col-md-12">

                    @if ($asset->company)
                        {{-- Company (read-only) --}}
                        <x-form.row :label="trans('general.company')" name="company_display" input_div_class="col-md-6">
                            <x-slot:input>
                                <p class="form-control-static">{!! $asset->company->present()->formattedNameLink !!}</p>
                            </x-slot:input>
                        </x-form.row>
                    @endif

                    @if ($asset->model->category)
                        {{-- Category (read-only) --}}
                        <x-form.row :label="trans('general.category')" name="category_display" input_div_class="col-md-6">
                            <x-slot:input>
                                <p class="form-control-static">{!! $asset->model->category->present()->formattedNameLink !!}</p>
                            </x-slot:input>
                        </x-form.row>
                    @endif

                    {{-- Model (read-only with fallback UI when the model
                         reference is broken — asset points at a deleted or
                         invalid model). --}}
                    <x-form.row :label="trans('admin/hardware/form.model')" name="model_display" input_div_class="col-md-8">
                        <x-slot:input>
                            <p class="form-control-static">
                                @if (($asset->model) && ($asset->model->name))
                                    {{ $asset->model->name }}
                                @else
                                    <span class="text-danger text-bold">
                                        <x-icon type="warning" />
                                        {{ trans('admin/hardware/general.model_invalid') }}
                                    </span>
                                    {{ trans('admin/hardware/general.model_invalid_fix') }}
                                    <a href="{{ route('hardware.edit', $asset->id) }}">
                                        <strong>{{ trans('admin/hardware/general.edit') }}</strong>
                                    </a>
                                @endif
                            </p>
                        </x-slot:input>
                    </x-form.row>

                    {{-- Asset name --}}
                    <x-form.row
                        :label="trans('general.name')"
                        name="name"
                        type="text"
                        :item="$asset"
                        input_div_class="col-md-8"
                    />

                    {{-- Status. The select gets id=modal-statuslabel_types
                         because the JS at the bottom of the page keys the
                         requestable-wrapper visibility toggle on that id. --}}
                    <x-form.row
                        :label="trans('admin/hardware/form.status')"
                        name="status_id"
                        input_div_class="col-md-8 required"
                    >
                        <x-slot:input>
                            <x-input.select
                                name="status_id"
                                id="modal-statuslabel_types"
                                :options="$statusLabel_list"
                                :selected="old('status_id')"
                                style="width: 100%"
                                aria-label="status_id"
                            />
                        </x-slot:input>
                    </x-form.row>

                    {{-- Requestable toggle. The outer form-group carries
                         id=requestable-wrapper — the snipeit.js handler
                         shows/hides it based on whether the selected
                         status is deployable (list of deployable status
                         ids handed off via data attribute), and
                         x-form.checkbox-row doesn't forward attributes to
                         its wrapper, so this stays hand-rolled. The
                         checkbox itself carries two data attributes that
                         drive its localStorage-preference behavior. --}}
                    <div
                        class="form-group"
                        id="requestable-wrapper"
                        data-deployable-status-ids="{{ json_encode($deployable_status_ids) }}"
                        @if (! $show_requestable_toggle) style="display: none;" @endif
                    >
                        <div class="col-md-9 col-md-offset-3">
                            <label class="form-control" for="requestable">
                                <input
                                    type="checkbox"
                                    value="1"
                                    name="requestable"
                                    id="requestable"
                                    data-user-preference-key="snipeit.checkin.requestable_default.{{ auth()->id() ?? 'guest' }}"
                                    data-had-old-input="{{ ((bool) old('requestable', false)) || session()->has('_old_input.requestable') ? '1' : '0' }}"
                                    @checked((bool) old('requestable', $asset->requestable))
                                />
                                {{ trans('admin/hardware/general.requestable') }}
                            </label>
                        </div>
                    </div>

                    <x-input.location-select
                        :label="trans('general.location')"
                        name="location_id"
                        :help_text="($asset->defaultLoc) ? trans('general.checkin_to_diff_location', ['default_location' => $asset->defaultLoc->name]) : null"
                        :selected="old('location_id')"
                        :company_id="$asset->company_id"
                    />

                    {{-- Update actual location  --}}
                    <x-form.radio-row
                        name="update_default_location"
                        selected="1"
                        :options="[
                            '1' => trans('admin/hardware/form.asset_location'),
                            '0' => trans('admin/hardware/form.asset_location_update_default_current'),
                        ]"
                    />

                    {{-- Checkout/Checkin date. The nested input-group carries
                         responsive col-* classes for the datetimepicker
                         layout at different breakpoints; kept as slot:input
                         since x-form.row doesn't nest an input-group inside
                         its input column. --}}
                    <x-form.row
                        :label="trans('admin/hardware/form.checkin_date')"
                        name="checkin_at"
                        label_class="col-sm-3 col-xs-12 col-sm-12"
                        input_div_class="col-md-8 col-xs-12 col-sm-12"
                    >
                        <x-slot:input>
                            <div class="input-group col-xl-5 col-lg-5 col-md-7 col-sm-9 col-xs-12 required">
                                <x-input.datetimepicker
                                    id="checkin_at"
                                    name="checkin_at"
                                    :value="old('checkin_at', date('Y-m-d H:i:s'))"
                                />
                            </div>
                        </x-slot:input>
                    </x-form.row>

                    {{-- Note --}}
                    <x-form.row
                        :label="trans('general.notes')"
                        name="note"
                        input_div_class="col-md-8"
                    >
                        <x-slot:input>
                            <textarea class="col-md-6 form-control" id="note" @required($snipeSettings->require_checkinout_notes) name="note">{{ old('note', $asset->note) }}</textarea>
                        </x-slot:input>
                    </x-form.row>

                    {{-- Custom fields --}}
                    @include('models/custom_fields_form', [
                        'model' => $asset->model,
                        'show_custom_fields_type' => 'checkin',
                    ])

                </div>

                <x-slot:customfooter>
                    <x-redirect_submit_options
                        index_route="hardware.index"
                        :button_label="trans('general.checkin')"
                        :disabled_select="! $asset->model"
                        :options="[
                            'index' => trans('admin/hardware/form.redirect_to_all', ['type' => trans('general.assets')]),
                            'item' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.asset')]),
                            'target' => $target_option,
                        ]"
                    />
                </x-slot:customfooter>

            </x-box>
        </x-form>
    </x-container>

@stop

