<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Transformers\CalendarEventsTransformer;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unified read endpoint for the calendar page. Queries the
 * calendar_events index table (populated by the HasCalendarEvents
 * trait's observer) and hydrates each row from its live source
 * model, so titles / urls / colors reflect current source state
 * rather than a denormalized snapshot.
 *
 * Filtering:
 *   - `start`, `end` (ISO-8601) - FullCalendar's visible range.
 *     Defaults to a wide six-month window centered on today.
 *   - `event_type[]` - array of allowed event_type values. Empty
 *     means "all types". Frontend filter buttons flip these on and
 *     off.
 *   - `limit` - hard cap on returned events. Defaults to 500 for the
 *     full calendar page and gets overridden downward for the
 *     dashboard "Today" widget. Guards the browser from a 10k-row
 *     JSON blob when a big install's calendar range straddles a
 *     dense date. Response is `{ events, truncated, total }` so the
 *     frontend can render a "narrow filters" banner when truncated.
 *
 * Access control:
 *   - Base gate: the viewer must be able to view AT LEAST ONE of the
 *     source types (Maintenance / Asset / License / User). A user
 *     with only license-view permission still legitimately wants the
 *     calendar to render their license expirations, so the endpoint
 *     shouldn't 403 them just because they can't see assets.
 *   - Source-specific access is enforced per-row via each source's
 *     own view policy - if the actor can't view the underlying model
 *     (FMCS mismatch, location scoping, etc.), the event is filtered
 *     out before it hits the response. That per-row gate does the
 *     actual scoping work.
 */
class CalendarEventsController extends Controller
{
    /**
     * Hard cap on returned rows. Two calendar contexts hit this
     * endpoint: the full calendar page (default 500) and the
     * dashboard's Today widget (much smaller, sends ?limit=25). A
     * request that asks for more than the ceiling silently gets
     * capped so the browser can't be exhausted.
     */
    private const LIMIT_CEILING = 500;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAnySource($request);

        [$rangeStart, $rangeEnd] = $this->resolveRange($request);
        $eventTypes = $this->resolveEventTypes($request);
        $limit = $this->resolveLimit($request);

        $baseQuery = $this->buildBaseQuery($rangeStart, $rangeEnd, $eventTypes);
        $total = (clone $baseQuery)->count();
        $rows = $baseQuery->orderBy('start')->limit($limit)->get();

        // Whether we hit the query limit. Honest signal for "there
        // might be more events after this batch". Comparing $total to
        // count($events) post-per-row-filter would report truncated=
        // true any time an FMCS-filtered event brought the returned
        // count below $total, misleading users into clicking "+N more"
        // links for events they never had permission to see.
        $hitLimit = $rows->count() >= $limit;

        $sourcesByType = $this->batchLoadSources($rows);
        $events = $this->buildEvents($rows, $sourcesByType, $request);

        return response()->json([
            'events' => (new CalendarEventsTransformer)->transformCalendarEvents($events),
            'total' => $total,
            'truncated' => $hitLimit,
        ]);
    }

    /**
     * Base gate: viewer must be able to view AT LEAST ONE registered
     * HasCalendarEvents source model. Source list comes from
     * CalendarEvent::sourceModels() so a new adopter of the trait is
     * picked up automatically. Per-row policy checks inside
     * buildEvents() handle the actual event-level scoping.
     */
    protected function authorizeAnySource(Request $request): void
    {
        $viewer = $request->user();
        foreach (CalendarEvent::sourceModels() as $sourceClass) {
            if ($viewer?->can('view', $sourceClass)) {
                return;
            }
        }
        abort(403);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveRange(Request $request): array
    {
        return [
            $request->filled('start')
                ? Carbon::parse($request->input('start'))
                : now()->subMonths(3)->startOfDay(),
            $request->filled('end')
                ? Carbon::parse($request->input('end'))
                : now()->addMonths(3)->endOfDay(),
        ];
    }

    /**
     * `event_type[]=a&event_type[]=b` (repeatable) or `event_type=a,b`
     * (single param, comma-split). Empty or missing means "all types".
     *
     * @return array<int, string>
     */
    protected function resolveEventTypes(Request $request): array
    {
        $eventTypes = $request->input('event_type');
        if (is_string($eventTypes)) {
            $eventTypes = array_filter(array_map('trim', explode(',', $eventTypes)));
        }

        return is_array($eventTypes) ? array_values(array_filter($eventTypes)) : [];
    }

    protected function resolveLimit(Request $request): int
    {
        $limit = (int) $request->input('limit', self::LIMIT_CEILING);

        return max(1, min($limit, self::LIMIT_CEILING));
    }

    /**
     * FullCalendar's fetchInfo passes ranges as [start, end) (end
     * exclusive), so a listDay view of today is start=today 00:00,
     * end=tomorrow 00:00. whereBetween is inclusive on both sides
     * though, which pulled tomorrow's midnight events into today's
     * listDay widget and inflated the truncation count. Explicit
     * `>= start` / `< end` matches FC's half-open interval semantics.
     */
    protected function buildBaseQuery(Carbon $rangeStart, Carbon $rangeEnd, array $eventTypes)
    {
        return CalendarEvent::query()
            ->where(function ($q) use ($rangeStart, $rangeEnd) {
                $q->where(function ($inner) use ($rangeStart, $rangeEnd) {
                    $inner->where('start', '>=', $rangeStart)
                        ->where('start', '<', $rangeEnd);
                })
                    ->orWhere(function ($inner) use ($rangeStart, $rangeEnd) {
                        $inner->where('end', '>=', $rangeStart)
                            ->where('end', '<', $rangeEnd);
                    })
                    ->orWhere(function ($inner) use ($rangeStart, $rangeEnd) {
                        $inner->where('start', '<', $rangeStart)
                            ->where('end', '>=', $rangeEnd);
                    });
            })
            ->when(! empty($eventTypes), fn ($q) => $q->whereIn('event_type', $eventTypes));
    }

    /**
     * One query per distinct source_type, keyed by id for O(1) lookup
     * in buildEvents. Avoids the N+1 a naive $row->source access
     * would produce. Includes trashed sources when the model uses
     * SoftDeletes so an event whose parent was soft-deleted between
     * the index write and the request still resolves for display.
     *
     * @return array<class-string, \Illuminate\Support\Collection>
     */
    protected function batchLoadSources($rows): array
    {
        $sourcesByType = [];
        foreach ($rows->groupBy('source_type') as $sourceType => $typeRows) {
            if (! class_exists($sourceType)) {
                continue;
            }
            $ids = $typeRows->pluck('source_id')->unique()->all();
            $sourcesByType[$sourceType] = $sourceType::query()
                ->when(
                    in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($sourceType), true),
                    fn ($q) => $q->withTrashed(),
                )
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');
        }

        return $sourcesByType;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildEvents($rows, array $sourcesByType, Request $request): array
    {
        $events = [];
        foreach ($rows as $row) {
            $source = $sourcesByType[$row->source_type][$row->source_id] ?? null;
            if (! $source) {
                // Source disappeared between index write and now
                // (mid-request delete without a completed observer
                // cascade). Skip so the client doesn't see a ghost
                // event; reconcile command cleans up the orphan on
                // its next pass.
                continue;
            }

            // Per-row access via each source's own view policy.
            // Without this, view-Asset holders without a particular
            // license access would still see its expiration event.
            // Sources without a view policy are treated as visible;
            // add policies to lock down access.
            if (! $request->user()?->can('view', $source)) {
                continue;
            }

            $events[] = $this->shapeEvent($row, $source);
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    protected function shapeEvent($row, $source): array
    {
        $allDay = $this->isAllDayField($source, $row->source_field);

        return [
            'id' => $row->id,
            'title' => $this->titleFor($source, $row->event_type),
            'start' => $this->formatDate($row->start, $allDay),
            'end' => $this->formatDate($row->end, $allDay),
            'allDay' => $allDay,
            'url' => $this->urlFor($source),
            'color' => $this->colorFor($source),
            'extendedProps' => [
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'source_field' => $row->source_field,
                'event_type' => $row->event_type,
            ],
        ];
    }

    /**
     * Resolve a display name from the source model, prefixed with a
     * human-readable label for the event_type so a viewer can tell at
     * a glance why a row is on the calendar (e.g. "Audit due: Laptop
     * #7" vs. just "Laptop #7"). Presenter's name() is the canonical
     * Snipe-IT accessor; falls through to common attribute names for
     * models that don't have a presenter wired up yet.
     */
    protected function titleFor($source, string $eventType): string
    {
        if (method_exists($source, 'present')) {
            $presenter = $source->present();
            if (method_exists($presenter, 'name')) {
                $name = (string) $presenter->name();
            }
        }

        $name ??= (string) ($source->display_name ?? $source->name ?? (class_basename($source).'#'.$source->getKey()));

        $label = $this->eventTypeLabel($eventType);

        return $label ? sprintf('%s: %s', $label, $name) : $name;
    }

    /**
     * Human-readable label for an event_type. Keyed on the canonical
     * event_type strings emitted by each model's
     * calendarEventDefinitions(); unknown types fall through to the
     * event_type itself so a new source at least reads coherently
     * before its label is registered.
     */
    protected function eventTypeLabel(string $eventType): string
    {
        $keys = [
            'maintenance.start' => 'general.calendar_event_maintenance',
            'asset.audit_due' => 'general.calendar_event_asset_audit_due',
            'asset.expected_checkin' => 'general.calendar_event_asset_expected_checkin',
            'asset.checkout' => 'general.calendar_event_asset_checkout',
            'asset.eol' => 'general.calendar_event_asset_eol',
            'asset.warranty_expiration' => 'general.calendar_event_asset_warranty_expiration',
            'license.expiration' => 'general.calendar_event_license_expiration',
            'license.termination' => 'general.calendar_event_license_termination',
            'user.end_date' => 'general.calendar_event_user_end_date',
        ];

        if (! array_key_exists($eventType, $keys)) {
            return $eventType;
        }

        return trans($keys[$eventType]);
    }

    /**
     * True when the underlying source field is cast as a bare date
     * (not a datetime). Rendering these on a timegrid as ISO-8601 with
     * a T00:00:00 component stacks the whole day's events at midnight;
     * FullCalendar's `allDay: true` mode turns them into a solid bar
     * across the day instead. Also flips the payload's start/end to
     * YYYY-MM-DD so FullCalendar doesn't reinterpret the missing time
     * as UTC midnight.
     */
    protected function isAllDayField($source, ?string $field): bool
    {
        if (! $field) {
            return false;
        }

        if (method_exists($source, 'calendarEventDefinitions')) {
            foreach ($source->calendarEventDefinitions() as $definition) {
                if (($definition['field'] ?? null) === $field && array_key_exists('all_day', $definition)) {
                    return (bool) $definition['all_day'];
                }
            }
        }

        if (! method_exists($source, 'getCasts')) {
            return false;
        }

        $cast = $source->getCasts()[$field] ?? null;

        return in_array($cast, ['date', 'immutable_date'], true);
    }

    protected function formatDate(mixed $date, bool $allDay): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        // Normalize to Carbon so we get toIso8601String() (Carbon-specific,
        // not on DateTimeInterface). Anything already Carbon stays as-is
        // via Carbon::instance's short-circuit; date strings and other
        // DateTimeInterface values get wrapped.
        $date = $date instanceof Carbon
            ? $date
            : ($date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date));

        return $allDay ? $date->format('Y-m-d') : $date->toIso8601String();
    }

    /**
     * Resolve a link to the source model. Presenter's route() (or
     * similar) is preferred so each source owns its own routing
     * conventions; falls back to a source-type-keyed default when
     * absent.
     */
    protected function urlFor($source): ?string
    {
        if (method_exists($source, 'present')) {
            $presenter = $source->present();
            if (method_exists($presenter, 'calendarUrl')) {
                return $presenter->calendarUrl();
            }
        }

        return null;
    }

    /**
     * Resolve a color for the event via the source model's
     * presenter. Null means "no per-event color" - frontend paints
     * from a per-event_type CSS palette so uncolored installs still
     * read cleanly.
     */
    protected function colorFor($source): ?string
    {
        if (method_exists($source, 'present')) {
            $presenter = $source->present();
            if (method_exists($presenter, 'calendarColor')) {
                return $presenter->calendarColor();
            }
        }

        return null;
    }
}
