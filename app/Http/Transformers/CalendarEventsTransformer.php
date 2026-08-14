<?php

namespace App\Http\Transformers;

use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Normalizes a collection of pre-shaped event arrays into
 * FullCalendar-v6 Event Object format.
 *
 * Callers build one array per event with keys mirroring FullCalendar's
 * own shape (id, title, start, end, url, color, extendedProps); this
 * transformer handles the last-mile bits every calendar endpoint would
 * otherwise duplicate:
 *
 *   - Carbon / DateTime values get ISO-8601 stringified (FullCalendar
 *     parses ISO-8601 reliably; passing raw Carbon objects works via
 *     PHP's json_encode but drifts across projects).
 *   - Missing keys default to null / empty string / empty array so
 *     client-side never has to guard on shape.
 *   - Unknown keys pass through untouched under extendedProps so a
 *     caller can hand any per-entity metadata to a custom
 *     eventClick handler without needing this transformer to know
 *     about it.
 *
 * Used by:
 *   - Api\CalendarEventsController::index (unified calendar page)
 *
 * Any new calendar-adjacent endpoint should route through this same
 * transformer so every calendar payload looks identical from the
 * client's perspective.
 */
class CalendarEventsTransformer
{
    /**
     * @param  iterable<array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public function transformCalendarEvents(iterable $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = $this->transformCalendarEvent($event);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function transformCalendarEvent(array $event): array
    {
        return [
            'id' => $event['id'] ?? null,
            'title' => $event['title'] ?? '',
            'start' => $this->normalizeDate($event['start'] ?? null),
            'end' => $this->normalizeDate($event['end'] ?? null),
            'url' => $event['url'] ?? null,
            'color' => $event['color'] ?? null,
            'extendedProps' => $event['extendedProps'] ?? [],
        ];
    }

    private function normalizeDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        if ($date instanceof CarbonInterface) {
            return $date->toIso8601String();
        }
        if ($date instanceof DateTimeInterface) {
            return $date->format(DateTimeInterface::ATOM);
        }

        return (string) $date;
    }
}
