@extends('layouts/default')

@section('title')
    {{ trans('admin/hardware/general.fulfill_multiple') }}
    @parent
@stop

{{-- Bulk-fulfill for asset-target requestable types (Component,
     AssetModel). Each row picks a specific Asset per request; the
     controller pre-computes the available-asset set per row so the
     picker is a plain <select> (no ajax pagination needed for the
     typical scope). Sibling template checkouts/fulfill-multiple.blade.php
     handles the user-target types (Accessory/Consumable/License). --}}

@section('content')

<x-container class="col-md-12">
    <x-form :route="$formRoute" id="fulfill_multiple_form">

        <x-box header="{{ $item->display_name ?? $item->name }}">

            @if ($remaining !== null)
                <x-form.static :label="trans('admin/components/general.remaining')">
                    {{ (int) $remaining }}
                </x-form.static>
            @endif

            @if ($item->company ?? null)
                <x-form.static :label="trans('general.company')">{!! $item->company->present()->formattedNameLink !!}</x-form.static>
            @endif

            @if ($item->category ?? null)
                <x-form.static :label="trans('general.category')">{!! $item->category->present()->formattedNameLink !!}</x-form.static>
            @endif

            {{-- $rowContext[$requestId] = ['availableAssets' => Collection, 'emptyMessage' => ?string]
                 Rows whose availableAssets is empty render the
                 row disabled (checkbox unavailable) with the
                 empty message so the admin sees WHY that row
                 can't be fulfilled from this screen. --}}
            @foreach ($pendingRequests as $request)
                @php ($context = $rowContext[$request->id] ?? ['availableAssets' => collect(), 'emptyMessage' => null])
                <x-checkout.asset-picker-row
                    :request="$request"
                    :requester="$request->user"
                    :available-assets="$context['availableAssets']"
                    :empty-message="$context['emptyMessage']"
                    :show-qty="! ($hideQty ?? false)"
                    :max-qty="$remaining"
                />
            @endforeach

            <x-slot:customfooter>
                <div class="col-md-9 col-md-offset-3">
                    <a href="{{ route('requests.index') }}" class="btn btn-default">
                        {{ trans('button.cancel') }}
                    </a>
                    <button type="submit" class="btn btn-primary" id="submit_button">
                        <x-icon type="checkmark" />
                        {{ trans('admin/hardware/general.fulfill_multiple') }}
                    </button>
                </div>
            </x-slot:customfooter>

        </x-box>

    </x-form>
</x-container>

@stop
