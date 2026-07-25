{{-- Inline "this field is locked because the app is in demo mode" notice.
     Self-gates on the editableOnDemo policy and on the item having a
     persisted id, so callers can drop it in a form column without
     wrapping their own @if. Pass :item="$user" so the id check happens
     inside the component. Default text is trans('admin/users/table.lock_passwords');
     override by putting a translation string in the slot.

     Deliberately does NOT carry the .help-block class that x-form.help
     applies: the snipeValidatorOptions.highlight callback in
     layouts/default.blade.php calls $group.find('.help-block').remove()
     on any form-group in error state, which would strip this notice on
     validation. The .demo-lock-notice class is here so a future style
     override can target it without matching every .text-warning use. --}}
@props([
    'item' => null,
])

@if (! Gate::allows('editableOnDemo') && ($item?->id))
    <div {{ $attributes->merge(['class' => 'demo-lock-notice', 'role' => 'note']) }}>
        <x-icon type="locked"/>
        {{ $slot->isEmpty() ? trans('admin/users/table.lock_passwords') : $slot }}
    </div>
@endif
