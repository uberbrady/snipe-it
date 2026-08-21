@extends('layouts/default')

@section('title')
    {{ trans('admin/hardware/general.fulfill_multiple') }}
    @parent
@stop

@section('content')

<x-container class="col-md-12">
    <x-form :route="$formRoute" id="fulfill_multiple_form">

        <x-box header="{{ $item->display_name ?? $item->name }} ({{ (int) $item->numRemaining() }} {{ trans('admin/components/general.remaining') }})">

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

            {{-- One row per pending CheckoutRequest, ordered
                 oldest-first (waiting-list). Admin ticks the
                 ones to fulfill; rows left unchecked stay
                 pending. Qty defaults to what was requested and
                 is capped at the item's numRemaining so the
                 server can trust the sum won't over-allocate,
                 but the controller re-checks under lock during
                 the transaction so a stale page load can't
                 over-fulfill on submit. --}}
            @foreach ($pendingRequests as $request)
                <x-checkout.recipient-row
                    :request="$request"
                    :requester="$request->user"
                    :company-id="$item->company_id ?? null"
                    :max-qty="$remaining"
                    :show-qty="! ($hideQty ?? false)"
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
