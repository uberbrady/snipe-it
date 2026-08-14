<?php

use App\Models\Traits\HasCalendarEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Migrations\Migration;

/**
 * One-shot backfill of the calendar_events index for every source
 * model that uses HasCalendarEvents at the time this migration runs.
 * Walks the sources, chunks the rows, and force-syncs each so their
 * declared date columns land as calendar_events rows.
 *
 * On a fresh install the source tables are usually empty at this
 * point (this migration runs early), so the backfill is a no-op and
 * the observer takes over from there. On an upgrade from an install
 * that already has maintenances / audits / etc., the backfill
 * populates the index so those existing records show up on the
 * calendar without waiting for a manual save on each one.
 *
 * Duplicates snipeit:reconcile-calendar-events's sync logic on
 * purpose - the command is meant to be re-runnable on demand later,
 * this migration is the one-time seed at deploy time. Kept small
 * enough that no shared helper class is needed between them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->discoverSources() as $sourceClass) {
            $this->backfillSource($sourceClass);
        }
    }

    public function down(): void
    {
        // No-op. The calendar_events table gets dropped by the
        // create-table migration's down(); trying to un-backfill
        // individual rows here would just reset the index to an
        // empty state that the observer would immediately fill on
        // the next source save anyway.
    }

    protected function backfillSource(string $sourceClass): void
    {
        $sourceClass::query()
            ->when(
                in_array(SoftDeletes::class, class_uses_recursive($sourceClass), true),
                fn ($q) => $q->withTrashed(),
            )
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $row->forceSyncCalendarEvents();
                }
            });
    }

    protected function discoverSources(): array
    {
        $sources = [];
        $modelDir = app_path('Models');
        $files = glob($modelDir.'/*.php');
        foreach ($files as $file) {
            $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }
            if (in_array(HasCalendarEvents::class, class_uses_recursive($class), true)) {
                $sources[] = $class;
            }
        }

        return $sources;
    }
};
