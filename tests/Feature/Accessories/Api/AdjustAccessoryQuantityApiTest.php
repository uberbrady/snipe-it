<?php

namespace Tests\Feature\Accessories\Api;

use App\Enums\ActionType;
use App\Models\Accessory;
use App\Models\AccessoryCheckout;
use App\Models\Actionlog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdjustAccessoryQuantityApiTest extends TestCase
{
    public function test_requires_edit_permission()
    {
        $accessory = Accessory::factory()->create(['qty' => 5]);

        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                'amount' => 3,
                'note' => 'restock',
            ])
            ->assertForbidden();
    }

    public function test_signed_amount_writes_quantity_adjust_log()
    {
        $actor = User::factory()->editAccessories()->create();
        $accessory = Accessory::factory()->create(['qty' => 5]);

        $this->actingAsForApi($actor)
            ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                'amount' => 3,
                'note' => 'restock from PO',
                'order_number' => 'PO-API-1',
            ])
            ->assertOk();

        $this->assertSame(8, (int) $accessory->fresh()->qty);

        $log = Actionlog::where('item_type', Accessory::class)
            ->where('item_id', $accessory->id)
            ->where('action_type', ActionType::QuantityAdjust->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(3, (int) $log->quantity);
        $this->assertSame('restock from PO', $log->note);
        // order_number moved off the log row to the Orders table — the
        // log carries order_id pointing at the newly-created Order.
        $order = $log->orderItem->order;
        $this->assertSame('PO-API-1', $order->order_number);
        $this->assertSame($actor->id, (int) $log->created_by);
    }

    public function test_negative_amount_decrements()
    {
        $actor = User::factory()->editAccessories()->create();
        $accessory = Accessory::factory()->create(['qty' => 10]);

        $this->actingAsForApi($actor)
            ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                'amount' => -4,
                'note' => 'damaged units removed',
            ])
            ->assertOk();

        $this->assertSame(6, (int) $accessory->fresh()->qty);
    }

    public function test_note_is_required()
    {
        // Snipe-IT convention: FormRequest validation errors on API
        // endpoints return 200 with a status:error body via
        // formatStandardApiResponse (Exceptions\Handler shapes this
        // globally). Below-in-use is different — that one is a
        // controller-level DomainException and returns 422 explicitly.
        $accessory = Accessory::factory()->create(['qty' => 5]);

        $this->actingAsForApi(User::factory()->editAccessories()->create())
            ->postJson(route('api.accessories.adjust-quantity', $accessory), ['amount' => 2])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('messages.note.0', 'The note field is required.');
    }

    public function test_zero_amount_writes_audit_log_without_changing_qty()
    {
        // Zero delta is an audit-only submission: user counted the shelf
        // and confirmed it still matches the DB, so we record the
        // QuantityAdjust log entry (with quantity=0) for provenance but
        // do not touch the on-hand column.
        $accessory = Accessory::factory()->create(['qty' => 5]);

        $this->actingAsForApi(User::factory()->editAccessories()->create())
            ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                'amount' => 0,
                'note' => 'shelf count matches',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(5, (int) $accessory->fresh()->qty);

        $log = Actionlog::where('item_type', Accessory::class)
            ->where('item_id', $accessory->id)
            ->where('action_type', ActionType::QuantityAdjust->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(0, (int) $log->quantity);
        $this->assertSame('shelf count matches', $log->note);
    }

    public function test_decrement_below_currently_checked_out_returns_422()
    {
        $accessory = Accessory::factory()->create(['qty' => 10]);
        AccessoryCheckout::factory()->count(4)->create([
            'accessory_id' => $accessory->id,
            'assigned_type' => User::class,
            'assigned_to' => User::factory()->create()->id,
        ]);

        $this->actingAsForApi(User::factory()->editAccessories()->create())
            ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                'amount' => -7, // would leave qty=3, below the 4 in use
                'note' => 'oops',
            ])
            ->assertStatus(422);

        $this->assertSame(10, (int) $accessory->fresh()->qty);
    }

    public function test_receipt_attaches_to_the_same_log_row_and_surfaces_in_files_tab()
    {
        Storage::fake();

        $accessory = Accessory::factory()->create(['qty' => 5]);
        $uploadsBefore = $accessory->uploads()->count();

        $this->actingAsForApi(User::factory()->editAccessories()->create())
            ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                'amount' => 3,
                'note' => 'restock with invoice',
                'order_number' => 'PO-API-2',
                'file' => UploadedFile::fake()->create('invoice-api.pdf', 32, 'application/pdf'),
            ])
            ->assertOk();

        $log = Actionlog::where('item_type', Accessory::class)
            ->where('item_id', $accessory->id)
            ->where('action_type', ActionType::QuantityAdjust->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull($log->filename);
        $this->assertStringEndsWith('.pdf', $log->filename);
        $this->assertSame(0, Actionlog::where('item_type', Accessory::class)
            ->where('item_id', $accessory->id)
            ->where('action_type', 'uploaded')
            ->count(), 'No separate uploaded log entry should have been created.');
        $this->assertSame($uploadsBefore + 1, $accessory->uploads()->count());
    }

    public function test_supplier_purchase_date_unit_cost_and_currency_land_on_order_and_order_item()
    {
        $accessory = Accessory::factory()->create(['qty' => 2]);
        $supplier = \App\Models\Supplier::factory()->create();

        $this->actingAsForApi(User::factory()->editAccessories()->create())
            ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                'amount' => 5,
                'note' => 'PO arrived',
                'order_number' => 'PO-FULL-META',
                'supplier_id' => $supplier->id,
                'purchase_date' => '2026-03-15',
                'unit_cost' => 12.3456,
                'currency' => 'EUR',
            ])
            ->assertOk();

        $log = Actionlog::where('item_type', Accessory::class)
            ->where('item_id', $accessory->id)
            ->where('action_type', ActionType::QuantityAdjust->value)
            ->latest('id')
            ->firstOrFail();

        $order = $log->orderItem->order;
        $this->assertSame('PO-FULL-META', $order->order_number);
        $this->assertSame($supplier->id, (int) $order->supplier_id);
        $this->assertSame('2026-03-15', $order->purchase_date->toDateString());
        $this->assertSame('EUR', $order->currency);

        $line = $order->orderItems()->firstOrFail();
        $this->assertSame(Accessory::class, $line->item_type);
        $this->assertSame($accessory->id, (int) $line->item_id);
        $this->assertSame(5, (int) $line->qty);
        $this->assertEquals(12.3456, (float) $line->price);
    }

    public function test_audit_only_zero_delta_with_no_order_metadata_skips_order_creation()
    {
        // Zero delta and no supplier / order number / date / cost /
        // currency = pure inventory audit. No Order row should be
        // created for these, since there was no transaction.
        $accessory = Accessory::factory()->create(['qty' => 5]);
        $ordersBefore = \App\Models\Order::count();

        $this->actingAsForApi(User::factory()->editAccessories()->create())
            ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                'amount' => 0,
                'note' => 'shelf count matches',
            ])
            ->assertOk();

        $this->assertSame($ordersBefore, \App\Models\Order::count());

        $log = Actionlog::where('item_type', Accessory::class)
            ->where('item_id', $accessory->id)
            ->where('action_type', ActionType::QuantityAdjust->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($log->order_item_id);
    }

    public function test_multiple_adjusts_with_same_order_number_dedupe_to_one_order()
    {
        // Repeated qty adjusts referencing the same order_number should
        // reuse the existing Order row (dedupe on order_number +
        // supplier + company + purchase_date), while each adjust still
        // produces its own OrderItem line (one line per event).
        $accessory = Accessory::factory()->create(['qty' => 0]);
        $supplier = \App\Models\Supplier::factory()->create();
        $actor = User::factory()->editAccessories()->create();

        foreach ([3, 4] as $delta) {
            $this->actingAsForApi($actor)
                ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                    'amount' => $delta,
                    'note' => 'same receipt, split line',
                    'order_number' => 'ORD-DEDUPE',
                    'supplier_id' => $supplier->id,
                    'purchase_date' => '2026-04-01',
                ])
                ->assertOk();
        }

        $orders = \App\Models\Order::where('order_number', 'ORD-DEDUPE')->get();
        $this->assertCount(1, $orders, 'Same order_number + supplier + date should reuse the existing Order row.');

        $items = $orders->first()->orderItems;
        $this->assertCount(2, $items, 'Each adjust event should have its own OrderItem line.');
        $this->assertEqualsCanonicalizing([3, 4], $items->pluck('qty')->map(fn ($q) => (int) $q)->all());
    }

    public function test_same_order_number_on_different_purchase_dates_creates_distinct_orders()
    {
        // Snipe-IT has no partial-receipt concept — every Order is a
        // completed receipt-in-hand. Same order_number appearing with
        // two different purchase_dates therefore represents two
        // distinct events, not staggered delivery, so each gets its
        // own Order row.
        $accessory = Accessory::factory()->create(['qty' => 0]);
        $supplier = \App\Models\Supplier::factory()->create();
        $actor = User::factory()->editAccessories()->create();

        foreach (['2026-04-01', '2026-04-15'] as $date) {
            $this->actingAsForApi($actor)
                ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                    'amount' => 5,
                    'note' => 'later receipt on same order number',
                    'order_number' => 'ORD-SPLIT-DATE',
                    'supplier_id' => $supplier->id,
                    'purchase_date' => $date,
                ])
                ->assertOk();
        }

        $this->assertSame(
            2,
            \App\Models\Order::where('order_number', 'ORD-SPLIT-DATE')->count(),
            'Different purchase_dates under the same order_number should be distinct Orders.',
        );
    }

    public function test_blank_order_number_creates_a_distinct_order_per_event()
    {
        // A blank order_number is a distinct transaction each time, not
        // a bucket to pool anonymous receipts into. Each adjust with
        // no order_number gets its own Order row.
        $accessory = Accessory::factory()->create(['qty' => 0]);
        $supplier = \App\Models\Supplier::factory()->create();
        $actor = User::factory()->editAccessories()->create();
        $ordersBefore = \App\Models\Order::count();

        foreach ([2, 3] as $delta) {
            $this->actingAsForApi($actor)
                ->postJson(route('api.accessories.adjust-quantity', $accessory), [
                    'amount' => $delta,
                    'note' => 'anonymous receipt',
                    'supplier_id' => $supplier->id,
                ])
                ->assertOk();
        }

        $this->assertSame($ordersBefore + 2, \App\Models\Order::count());
    }
}
