<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-order data model. `orders` is one row per real-world PO (a
 * supplier + date + optional PO number). `order_items` is the line-items
 * on that PO, polymorphic to the inventory model so a single PO can carry
 * accessories, consumables, components, assets, and license seats.
 *
 * Replaces the parent-level `order_number` column that used to live on
 * each inventory table. The old column misrepresented current state on
 * models that get replenished across many POs (accessories / consumables
 * / components); consolidating into a dedicated Order model + polymorphic
 * line-items lets each purchase event be recorded independently, and
 * lets action_logs point at the Order (per-event PO context) rather than
 * carrying a raw string.
 *
 * No UI or permission model is included yet — this migration only handles
 * the storage. Rows arrive via the adjust-quantity flow, the importer,
 * and the backfill migrations that follow this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            // Parent-level FMCS scope — a PO belongs to the company that
            // placed it. Same convention as accessories.company_id /
            // consumables.company_id / etc. so CompanyableScope hooks
            // through cleanly if / when this model opts into it.
            $table->unsignedBigInteger('company_id')->nullable();
            $table->date('purchase_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Free-text search on inventory models hits order_number
            // through the polymorphic order_items relation, so the join
            // needs a fast index. Same for the supplier and company
            // filters used by FMCS scoping.
            $table->index('order_number');
            $table->index('supplier_id');
            $table->index('company_id');
            $table->index('created_by');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            // Polymorphic pointer at the inventory row this line refers
            // to. Uses the same shape as action_logs.item_type /
            // action_logs.item_id so the two tables read consistently.
            $table->string('item_type');
            $table->unsignedBigInteger('item_id');
            $table->integer('qty')->default(1);
            // (20,4) matches the widest precision already in use in the
            // codebase (components.purchase_cost). Covers 3-decimal
            // currencies and low-per-unit fractional prices without
            // rounding drift on aggregate totals.
            $table->decimal('price', 20, 4)->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
