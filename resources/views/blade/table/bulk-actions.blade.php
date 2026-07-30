@props([
        'action_route',
        'model_name' => 'asset',
        'actions' => null,
    ])
@aware(['name'])

@php
    $formId = Illuminate\Support\Str::camel($name) . 'Form';
    $buttonId = Illuminate\Support\Str::camel($name) . 'Button';
    $selectId = Illuminate\Support\Str::camel($name) . 'Select';
@endphp

<div id="{{ $formId }}" style="min-width:400px" class="hidden-print">
    <form
            method="POST"
            action="{{ $action_route }}"
            accept-charset="UTF-8"
            class="form-inline"
            id="{{ $formId }}"
    >
        @csrf

        {{-- sort/order only used on first-use before cookie is set --}}
        <input name="sort" type="hidden" value="{{ "{$model_name}.id" }}">
        <input name="order" type="hidden" value="asc">
        <label for="bulk_actions">
            <span class="sr-only">
                {{ trans('button.bulk_actions') }}
            </span>
        </label>
        <select
            id="{{ $selectId }}"
            name="bulk_actions"
            class="form-control select2"
            aria-label="bulk_actions"
            style="min-width: 350px;"
            @if ($actions)
                data-dynamic-actions="{{ json_encode($actions) }}"
                data-placeholder="{{ trans('general.bulk_actions_selection_prompt') }}"
            @endif
        >
            @if ($actions)
                <option value=""></option>
            @else
                {{ $slot }}
            @endif
        </select>

        <button class="btn btn-theme" id="{{ $buttonId }}" disabled>{{ trans('button.go') }}</button>
    </form>
</div>
