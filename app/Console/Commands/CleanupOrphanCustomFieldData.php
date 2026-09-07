<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Null out custom field values on assets that aren't part of the asset's
 * model's fieldset.
 *
 * Every custom field lives in its own physical column on `assets`
 * (_snipeit_*), regardless of which fieldset(s) it belongs to. When an
 * asset model's fieldset is switched (or a field is removed from a
 * fieldset), the old values are left sitting in those columns even
 * though the UI no longer shows or edits them for that model. This
 * command finds and clears that stale data.
 *
 * Strategy: bucket asset models by fieldset_id (including models with no
 * fieldset assigned at all, and models pointing at a fieldset_id that no
 * longer exists), work out which custom field columns are NOT valid for
 * each bucket, and issue one batched UPDATE per bucket rather than one
 * per custom field.
 */
class CleanupOrphanCustomFieldData extends Command
{
    protected $signature = 'snipeit:orphan-customfields
        {--commit : Actually null out the orphaned values. Default is dry-run.}
        {--include-trashed : Also clean up soft-deleted assets.}
        {--chunk=500 : Assets updated per batch. Larger = fewer round trips, longer lock hold.}';

    protected $description = "Null out custom field values on assets that aren't part of the asset's model's fieldset (stale data left over from switching a model's fieldset or removing a field from one).";

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $includeTrashed = (bool) $this->option('include-trashed');
        $chunk = max(50, (int) $this->option('chunk'));

        // Every custom field's db_column, but only the ones that actually
        // exist on the assets table. A custom_fields row whose db_column
        // isn't a real column is a separate data-integrity problem (see
        // snipeit:regenerate-fieldnames) and is skipped here, not guessed at.
        $fields = CustomField::get(['id', 'name', 'db_column']);
        $allColumns = [];
        $missingColumns = [];
        foreach ($fields as $field) {
            if (Schema::hasColumn('assets', $field->db_column)) {
                $allColumns[] = $field->db_column;
            } else {
                $missingColumns[] = $field;
            }
        }

        if (! empty($missingColumns)) {
            warning(sprintf(
                '%d custom field(s) have a db_column that does not exist on the assets table and will be skipped: %s. Run snipeit:regenerate-fieldnames to investigate.',
                count($missingColumns),
                collect($missingColumns)->map(fn ($f) => "{$f->name} ({$f->db_column})")->implode(', ')
            ));
        }

        if (empty($allColumns)) {
            info('No usable custom field columns found. Nothing to do.');

            return self::SUCCESS;
        }

        // Bucket asset models by fieldset_id, including:
        //  - null: no fieldset assigned at all -> every custom field value is orphaned.
        //  - a fieldset_id with no matching custom_fieldsets row -> dangling reference,
        //    treated the same as "no fieldset" since we can't trust it.
        $fieldsetIds = AssetModel::query()->select('fieldset_id')->distinct()->pluck('fieldset_id');

        $plan = [];
        foreach ($fieldsetIds as $fieldsetId) {
            $fieldset = $fieldsetId ? CustomFieldset::find($fieldsetId) : null;
            $validColumns = $fieldset ? $fieldset->fields->pluck('db_column')->all() : [];

            $columnsToNull = array_values(array_diff($allColumns, $validColumns));
            if (empty($columnsToNull)) {
                continue;
            }

            $modelIds = AssetModel::query()
                ->when(
                    $fieldsetId,
                    fn ($q) => $q->where('fieldset_id', $fieldsetId),
                    fn ($q) => $q->whereNull('fieldset_id')
                )
                ->pluck('id')
                ->all();

            if (empty($modelIds)) {
                continue;
            }

            $label = match (true) {
                $fieldset !== null => $fieldset->name,
                $fieldsetId !== null => "(dangling fieldset #{$fieldsetId})",
                default => '(no fieldset)',
            };

            $plan[] = [
                'label' => $label,
                'model_ids' => $modelIds,
                'columns' => $columnsToNull,
                'affected' => 0,
            ];
        }

        // Assets whose model_id doesn't match any row in `models` at all
        // can't be assigned a "valid" set of fields, so they're reported
        // for triage but never touched by this command.
        $orphanModelAssetCount = $this->orphanModelAssetQuery($includeTrashed)->count();

        if (empty($plan)) {
            info('No orphaned custom field data found. Nothing to do.');
            if ($orphanModelAssetCount > 0) {
                warning("{$orphanModelAssetCount} asset(s) have a model_id that doesn't match any asset model and were skipped.");
            }

            return self::SUCCESS;
        }

        // Count affected rows per bucket before doing anything destructive.
        $summaryRows = [];
        $totalAffected = 0;
        foreach ($plan as $i => $row) {
            $count = $this->matchQuery($row['model_ids'], $row['columns'], $includeTrashed)->count();
            $plan[$i]['affected'] = $count;
            $totalAffected += $count;

            if ($count > 0) {
                $summaryRows[] = [
                    $row['label'],
                    count($row['model_ids']),
                    count($row['columns']),
                    number_format($count),
                ];
            }
        }

        if ($totalAffected === 0) {
            info('No orphaned custom field data found. Nothing to do.');

            return self::SUCCESS;
        }

        table(['Fieldset', 'Models', 'Orphaned columns', 'Assets affected'], $summaryRows);
        note(sprintf('Total: %s asset row(s) have at least one orphaned custom field value.', number_format($totalAffected)));

        if ($orphanModelAssetCount > 0) {
            warning("{$orphanModelAssetCount} asset(s) have a model_id that doesn't match any asset model and were skipped (can't determine their valid fields).");
        }

        note('This bypasses Eloquent model events, so no per-asset audit log entries will be created for these changes.');

        if (! $commit) {
            note('Dry run. Pass --commit to actually null out these values. Back up your database first -- this cannot be undone.');

            return self::SUCCESS;
        }

        warning(sprintf('About to null out orphaned custom field values on %s asset row(s). This cannot be undone.', number_format($totalAffected)));
        if (! confirm('Proceed?', default: false)) {
            info('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $updatedTotal = 0;

        foreach ($plan as $row) {
            if ($row['affected'] === 0) {
                continue;
            }

            $updatedTotal += $this->nullOrphanedColumns(
                $row['model_ids'],
                $row['columns'],
                $includeTrashed,
                $row['affected'],
                $chunk,
                $row['label']
            );
        }

        info(sprintf('Updated %s asset row(s) in %.2fs.', number_format($updatedTotal), microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * Base query for assets belonging to one of $modelIds that still have
     * at least one of $columns set (so counts/updates ignore rows that
     * are already clean).
     */
    private function matchQuery(array $modelIds, array $columns, bool $includeTrashed): Builder
    {
        $query = $includeTrashed ? Asset::withTrashed() : Asset::query();

        return $query->whereIn('model_id', $modelIds)
            ->where(function ($q) use ($columns) {
                foreach ($columns as $column) {
                    $q->orWhereNotNull($column);
                }
            });
    }

    /**
     * Assets whose model_id doesn't match any row in the models table.
     */
    private function orphanModelAssetQuery(bool $includeTrashed): Builder
    {
        $query = $includeTrashed ? Asset::withTrashed() : Asset::query();

        return $query->whereNotExists(function ($q) {
            $q->from('models')->whereColumn('models.id', 'assets.model_id');
        });
    }

    /**
     * Chunked update: fetch ids first, update by primary key. Keeps each
     * UPDATE small (bounded by chunk size) and gives the progress bar
     * meaningful ticks, at the cost of two queries per chunk.
     */
    private function nullOrphanedColumns(array $modelIds, array $columns, bool $includeTrashed, int $expected, int $chunk, string $label): int
    {
        $updated = 0;
        $progress = progress(label: "Nulling orphaned columns: {$label}", steps: $expected);
        $progress->start();

        $updates = array_fill_keys($columns, null);

        while (true) {
            $ids = $this->matchQuery($modelIds, $columns, $includeTrashed)->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $query = $includeTrashed ? Asset::withTrashed() : Asset::query();
            $n = $query->whereIn('id', $ids)->update($updates);
            $updated += $n;
            $progress->advance($n);
        }

        $progress->finish();

        return $updated;
    }
}
