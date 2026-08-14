<?php

use App\Models\Traits\HasCalendarEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Migrations\Migration;

/**
 * Second-pass backfill for calendar_events. The first backfill
 * migration ran when only Maintenance used HasCalendarEvents; this
 * one runs after Asset / License / User adopted the trait so any
 * install that already applied the earlier migration also picks up
 * the new sources without a manual reconcile.
 *
 * Same discover-and-force-sync logic as the first backfill: idempotent
 * on rows that already have an index entry (updateOrCreate-shaped
 * lookup in the trait uses withTrashed to sidestep the unique
 * constraint even for restored rows), so re-running for
 * already-synced Maintenance rows is a no-op.
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
        // No-op. See sibling backfill migration.
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
