@props([
    'request',
    'requester',
    'availableAssets',
    'showQty' => true,
    'maxQty' => null,
    'defaultChecked' => false,
    'emptyMessage' => null,
])

{{-- Row variant of the bulk-fulfill form for types where the
     checkout target is an Asset (not a User):
       - Component installs INTO an asset (per-request, admin picks
         from the requester's assigned assets)
       - AssetModel fulfills via picking a specific Asset OF that
         model (from the model's available assets)

     The available-asset set is pre-computed by the controller
     (small, deterministic list) so the picker is a plain <select>
     rather than the ajax select2 - no pagination needed for the
     typical scope, and the "auto-pick next available" default just
     falls out of pre-selecting the first option.

     Field shape matches the user-target recipient-row so the
     controller-side per-row iteration stays uniform:
         enabled_requests[]              = list of request ids ticked
         asset_id[<request_id>]          = target asset for that row
         qty[<request_id>]               = qty for that row (or 1 if showQty=false)
         notes[<request_id>]             = admin/checkout note --}}
<fieldset class="form-group has-feedback" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
    <legend class="col-sm-3 col-md-3 control-label" style="width: auto; padding: 0 10px; border: 0; margin-bottom: 0;">
        <label class="form-control" style="display: inline-flex; align-items: center; gap: 8px; margin-bottom: 0;">
            <input
                type="checkbox"
                name="enabled_requests[]"
                value="{{ (int) $request->id }}"
                @checked($defaultChecked || in_array((int) $request->id, (array) old('enabled_requests', []), true))
                @if ($availableAssets->isEmpty()) disabled @endif
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

    <div class="form-group {{ $errors->has('asset_id.'.$request->id) ? 'has-error' : '' }}">
        <label for="fulfill-asset-{{ $request->id }}" class="col-md-3 control-label">
            {{ trans('general.asset') }}
        </label>
        <div class="col-md-7">
            @if ($availableAssets->isEmpty())
                <p class="form-control-static text-muted">
                    <em>{{ $emptyMessage ?? trans('general.no_results') }}</em>
                </p>
            @else
                <select
                    class="form-control select2"
                    id="fulfill-asset-{{ $request->id }}"
                    name="asset_id[{{ $request->id }}]"
                    aria-label="{{ trans('general.asset') }}"
                >
                    @foreach ($availableAssets as $asset)
                        <option
                            value="{{ $asset->id }}"
                            @selected(old('asset_id.'.$request->id, $availableAssets->first()->id) == $asset->id)
                        >
                            {{ $asset->present()->fullName }}
                        </option>
                    @endforeach
                </select>
            @endif
            <div class="col-md-8" style="padding-left: 0;">
                <x-form.error :name="'asset_id.'.$request->id" />
            </div>
        </div>
    </div>

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
        {{-- AssetModel rows fulfill one asset per request, so qty
             is fixed at 1. Hidden input keeps the POST shape
             uniform with the qty-tracked types. --}}
        <input type="hidden" name="qty[{{ $request->id }}]" value="1">
    @endif

    <x-form.row
        :label="trans('admin/hardware/form.notes')"
        :name="'notes['.$request->id.']'"
        type="textarea"
        :default="old('notes.'.$request->id, $request->notes ?? '')"
    />
</fieldset>
