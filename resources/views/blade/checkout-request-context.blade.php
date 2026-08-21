@props([
    'request' => null,
    'others' => null,
])

{{-- Rendered on the four checkout screens (asset / accessory /
     consumable / component / license) when the admin arrived from
     a /requests row (?request_id=N). Two boxes: the current request
     for context, and the waiting list of other open requesters so
     the admin can arbitrate who gets it. Renders nothing when
     $request is null, so callers can drop it in unconditionally. --}}
@if (! empty($request))
    <x-box header="{{ trans('admin/hardware/general.requested') }}">
        <x-page-data>
            <x-data-row :label="trans('admin/hardware/table.requesting_user')">
                <a href="{{ route('users.show', $request->user->id) }}">{{ $request->user->display_name }}</a>
            </x-data-row>

            <x-data-row :label="trans('admin/hardware/table.requested_date')" icon_type="calendar">
                {{ $request->created_at->format($snipeSettings->date_display_format.' '.$snipeSettings->time_display_format) }}
                <span class="text-muted">{{ $request->created_at->diffForHumans(['parts' => 2]) }}</span>
            </x-data-row>

            <x-data-row :label="trans('admin/hardware/general.requested')">
                {{ (int) $request->quantity }}
            </x-data-row>

            @if ($request->start_date)
                <x-data-row :label="trans('general.start_date')" icon_type="start_date">
                    {{ $request->start_date->format($snipeSettings->date_display_format) }}
                </x-data-row>
            @endif

            @if ($request->end_date)
                <x-data-row :label="trans('general.end_date')" icon_type="end_date">
                    {{ $request->end_date->format($snipeSettings->date_display_format) }}
                </x-data-row>
            @endif

            @if ($request->notes)
                <x-data-row :label="trans('general.notes')">
                    {{ $request->notes }}
                </x-data-row>
            @endif
        </x-page-data>
    </x-box>

    @if (! empty($others) && $others->isNotEmpty())
        <x-box header="{{ trans('admin/hardware/table.pending_requesters') }}">
            @foreach ($others as $pending)
                @if (! $loop->first)
                    <hr>
                @endif
                <x-page-data>
                    <x-data-row :label="trans('admin/hardware/table.requesting_user')">
                        <a href="{{ route('users.show', $pending->user->id) }}">{{ $pending->user->display_name }}</a>
                    </x-data-row>

                    <x-data-row :label="trans('admin/hardware/table.requested_date')" icon_type="calendar">
                        {{ $pending->created_at->format($snipeSettings->date_display_format.' '.$snipeSettings->time_display_format) }}
                        <span class="text-muted">{{ $pending->created_at->diffForHumans(['parts' => 2]) }}</span>
                    </x-data-row>

                    <x-data-row :label="trans('admin/hardware/general.requested')">
                        {{ (int) $pending->quantity }}
                    </x-data-row>

                    @if ($pending->start_date)
                        <x-data-row :label="trans('general.start_date')" icon_type="start_date">
                            {{ $pending->start_date->format($snipeSettings->date_display_format) }}
                        </x-data-row>
                    @endif

                    @if ($pending->end_date)
                        <x-data-row :label="trans('general.end_date')" icon_type="end_date">
                            {{ $pending->end_date->format($snipeSettings->date_display_format) }}
                        </x-data-row>
                    @endif

                    @if ($pending->notes)
                        <x-data-row :label="trans('general.notes')">
                            {{ $pending->notes }}
                        </x-data-row>
                    @endif
                </x-page-data>
            @endforeach
        </x-box>
    @endif
@endif
