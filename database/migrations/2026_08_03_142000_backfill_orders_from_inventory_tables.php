<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the Orders / OrderItems tables from the pre-existing
 * order_number columns on accessories, consumables, components, assets,
 * and licenses. One Order row is created per unique
 * (order_number, supplier_id, company_id) tuple across all five tables,
 * and each source inventory row becomes an OrderItem line under that
 * Order.
 *
 * The dedupe key is intentionally (order_number, supplier_id, company_id)
 * rather than just order_number: a raw string like "PO-42" can legitimately
 * recur across different suppliers or across companies in FMCS installs,
 * and collapsing them would rewrite history.
 *
 * Rows with a null / empty order_number are skipped — they represent
 * inventory rows never associated with any order, and there's nothing
 * for the Orders table to record.
 *
 * The follow-up migration (`drop_order_number_from_inventory_tables`)
 * removes the source columns once this backfill has run.
 */
return new class extends Migration
{
    /**
     * Model class => source table name. Order determines the sequence
     * OrderItem rows are created in (purely cosmetic — a chronological
     * report would sort by Order.purchase_date or Order.created_at
     * anyway).
     */
    private const SOURCES = [
        \App\Models\Accessory::class => 'accessories',
        \App\Models\Consumable::class => 'consumables',
        \App\Models\Component::class => 'components',
        \App\Models\Asset::class => 'assets',
        \App\Models\License::class => 'licenses',
    ];

    public function up(): void
    {
        // Order dedupe cache: (order_number|supplier_id|company_id) => order_id.
        // Keeps us from hitting the DB with a lookup per source row.
        $orderIndex = [];

        // Order.currency stays NULL for backfilled rows. The source
        // inventory tables didn't have their own currency column, and
        // stamping every historical Order with today's system
        // default_currency would fabricate information we don't
        // actually have — orders placed years ago may have been in a
        // different currency than the install's current setting.
        // Downstream display code can fall back to
        // $snipeSettings->default_currency at render time (matching how
        // the pre-Orders info panels rendered purchase_cost anyway).

        foreach (self::SOURCES as $modelClass => $table) {
            // Defensive skip: if a source table has already had its
            // order_number column dropped (partial rerun, hand rollback,
            // schema drift), there's nothing to backfill from and the
            // migration should keep moving rather than blow up.
            if (! Schema::hasColumn($table, 'order_number')) {
                continue;
            }

            $qtyColumn = ($table === 'licenses') ? 'seats' : 'qty';
            $hasQtyColumn = Schema::hasColumn($table, $qtyColumn);

            DB::table($table)
                ->select([
                    'id',
                    'order_number',
                    'supplier_id',
                    'company_id',
                    'purchase_date',
                    'purchase_cost',
                    'created_by',
                    'created_at',
                ])
                ->whereNotNull('order_number')
                ->where('order_number', '!=', '')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($modelClass, $table, $hasQtyColumn, $qtyColumn, &$orderIndex) {
                    // Chunked pull grabs qty separately since not every
                    // source table has the same qty-column name and we
                    // want the primary select above to stay portable.
                    $qtyMap = $hasQtyColumn
                        ? DB::table($table)
                            ->whereIn('id', $rows->pluck('id'))
                            ->pluck($qtyColumn, 'id')
                            ->all()
                        : [];

                    foreach ($rows as $row) {
                        $key = ($row->order_number ?? '')
                            .'|'.($row->supplier_id ?? '')
                            .'|'.($row->company_id ?? '');

                        if (! array_key_exists($key, $orderIndex)) {
                            $orderIndex[$key] = DB::table('orders')->insertGetId([
                                'order_number' => $row->order_number,
                                'supplier_id' => $row->supplier_id,
                                'company_id' => $row->company_id,
                                'purchase_date' => $row->purchase_date,
                                'created_by' => $row->created_by,
                                'created_at' => $row->created_at ?? now(),
                                'updated_at' => $row->created_at ?? now(),
                            ]);
                        }

                        DB::table('order_items')->insert([
                            'order_id' => $orderIndex[$key],
                            'item_type' => $modelClass,
                            'item_id' => $row->id,
                            'qty' => max(1, (int) ($qtyMap[$row->id] ?? 1)),
                            'price' => $row->purchase_cost,
                            'created_at' => $row->created_at ?? now(),
                            'updated_at' => $row->created_at ?? now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Reversible only in a coarse sense — the drop wipes every row
        // in both tables rather than trying to reconstruct the original
        // per-source-column state. Combined with the follow-up
        // drop-columns migration (which does have a real down()), a full
        // rollback restores the pre-Orders layout.
        DB::table('order_items')->delete();
        DB::table('orders')->delete();
    }
};
