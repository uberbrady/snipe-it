@props([
    'request',
    'requester',
    'companyId' => null,
    'maxQty' => null,
    'defaultChecked' => false,
    'showQty' => true,
])

{{-- One row of the bulk-fulfill form. Renders a checkbox to opt this
     row into the batch, a user-select prefilled with the requester
     (editable, so admin can hand the item to someone else if the
     original requester is out sick), a qty input prefilled from the
     row's requested quantity, and the requester's notes as pre-
     filled context the admin can amend before checkout.

     Field names are keyed by CheckoutRequest id so the server can
     correlate the opt-in list with each row's per-field values
     without index-tracking. See bulk-fulfill controllers for the
     paired shape:
         enabled_requests[]         = list of request ids ticked
         user_id[<request_id>]      = target user for that row
         qty[<request_id>]          = qty for that row
         notes[<request_id>]        = admin/checkout note for that row

     Sign-in-place is deliberately absent. That flow assumes the
     requester is standing at the admin's device to sign in person,
     which doesn't match a bulk batch where the admin is processing
     multiple people from their own desk. Single-target checkout
     screens keep their sign-in-place checkbox unchanged. --}}
<fieldset class="form-group has-feedback" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
    <legend class="col-sm-3 col-md-3 control-label" style="width: auto; padding: 0 10px; border: 0; margin-bottom: 0;">
        <label class="form-control" style="display: inline-flex; align-items: center; gap: 8px; margin-bottom: 0;">
            <input
                type="checkbox"
                name="enabled_requests[]"
                value="{{ (int) $request->id }}"
                @checked($defaultChecked || in_array((int) $request->id, (array) old('enabled_requests', []), true))
                aria-label="{{ trans('general.fulfill') }} #{{ $request->id }}"
            >
            <span>#{{ $request->id }}</span>
            @if ($requester)
                <span class="text-muted">
                    &mdash; {{ $requester->display_name }}
                </span>
            @endif
        </label>
    </legend>

    <x-input.user-select
        :label="trans('general.user')"
        name="user_id[{{ $request->id }}]"
        :selected="old('user_id.'.$request->id, $requester?->id)"
        :companyId="$companyId"
        wrapperId="fulfill-user-{{ $request->id }}"
        id="fulfill-user-select-{{ $request->id }}"
    />

    @if ($showQty)
        <div class="form-group {{ $errors->has('qty.'.$request->id) ? 'has-error' : '' }}">
            <label for="fulfill-qty-{{ $request->id }}" class="col-md-3 control-label">{{ trans('general.qty') }}</label>
            <div class="col-md-7 col-sm-12">
                <div class="col-md-2" style="padding-left: 0">
                    <input
                        class="form-control"
                        type="number"
                        id="fulfill-qty-{{ $request->id }}"
                        name="qty[{{ $request->id }}]"
                        value="{{ old('qty.'.$request->id, (int) $request->quantity) }}"
                        min="1"
                        @if ($maxQty !== null) max="{{ $maxQty }}" @endif
                        aria-label="{{ trans('general.qty') }}"
                    >
                </div>
            </div>
            <div class="col-md-8 col-md-offset-3"><x-form.error :name="'qty.'.$request->id" /></div>
        </div>
    @else
        {{-- Types where qty is meaningless (license = one seat per
             requester, asset = 1:1) hide the input but keep a
             hidden value=1 so the controller's per-row shape stays
             uniform across every requestable type. --}}
        <input type="hidden" name="qty[{{ $request->id }}]" value="1">
    @endif

    <x-form.row
        :label="trans('admin/hardware/form.notes')"
        :name="'notes['.$request->id.']'"
        type="textarea"
        :default="old('notes.'.$request->id, $request->notes ?? '')"
    />
</fieldset>
