<?php

namespace Tests\Feature\CheckoutRequests;

use App\Actions\CheckoutRequests\FulfillCheckoutRequestAction;
use App\Enums\CheckoutRequestState;
use App\Exceptions\InvalidCheckoutRequestTransition;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\CheckoutRequest;
use App\Models\User;
use Tests\TestCase;

/**
 * Codifies which state transitions are legal + which throw. The
 * state column drives every lifecycle decision downstream (admin
 * queue filters, requester tab, Requestable::isRequestedBy gate,
 * withCount('openRequests') for the requests_counter replacement)
 * so a regression here silently corrupts the queue.
 *
 * Terminal states (fulfilled, canceled) are irreversible. Same-
 * state writes (fulfill an already-fulfilled row) no-op silently so
 * the fulfillment hook is safe to fire twice on a rapid retry.
 */
class StateMachineTest extends TestCase
{
    public function test_new_request_defaults_to_pending(): void
    {
        $request = CheckoutRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'requestable_id' => Asset::factory()->create()->id,
            'requestable_type' => Asset::class,
        ]);

        $this->assertSame(CheckoutRequestState::Pending, $request->state);
    }

    public function test_pending_transitions_to_fulfilled_via_action(): void
    {
        $request = CheckoutRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'requestable_id' => Asset::factory()->create()->id,
            'requestable_type' => Asset::class,
        ]);

        FulfillCheckoutRequestAction::run($request);
        $request->refresh();

        $this->assertSame(CheckoutRequestState::Fulfilled, $request->state);
        $this->assertNotNull($request->fulfilled_at);
    }

    public function test_pending_transitions_to_canceled_via_cancel_request(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $request = CheckoutRequest::factory()->create([
            'user_id' => $user->id,
            'requestable_id' => $asset->id,
            'requestable_type' => Asset::class,
        ]);

        $affected = $asset->cancelRequest($user->id);
        $request->refresh();

        $this->assertSame(1, $affected);
        $this->assertSame(CheckoutRequestState::Canceled, $request->state);
        $this->assertNotNull($request->canceled_at);
    }

    public function test_canceled_cannot_be_fulfilled(): void
    {
        // Terminal-state guard: a canceled row is done, and the
        // fulfillment hook must not resurrect it if the same user
        // later gets a checkout for the same item.
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $request = CheckoutRequest::factory()->create([
            'user_id' => $user->id,
            'requestable_id' => $asset->id,
            'requestable_type' => Asset::class,
        ]);
        $asset->cancelRequest($user->id);
        $request->refresh();

        $this->expectException(InvalidCheckoutRequestTransition::class);
        FulfillCheckoutRequestAction::run($request);
    }

    public function test_fulfilled_is_idempotent(): void
    {
        // The fulfillment hook can fire twice (retry, tests). A
        // second call on a fulfilled row must no-op instead of
        // throwing so the checkout controller doesn't 500 on a
        // benign re-entry.
        $request = CheckoutRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'requestable_id' => Asset::factory()->create()->id,
            'requestable_type' => Asset::class,
        ]);
        FulfillCheckoutRequestAction::run($request);
        $request->refresh();
        $firstFulfilledAt = $request->fulfilled_at;

        FulfillCheckoutRequestAction::run($request);
        $request->refresh();

        $this->assertSame(CheckoutRequestState::Fulfilled, $request->state);
        // Second call didn't overwrite the original timestamp,
        // which would happen if the guard was missing.
        $this->assertEquals($firstFulfilledAt->timestamp, $request->fulfilled_at->timestamp);
    }

    public function test_cancel_request_only_touches_pending_rows(): void
    {
        // If a user's request is already fulfilled (e.g. auto-
        // fulfilled by a prior checkout), a later cancel by the
        // same user for the same item must not flip the fulfilled
        // row back to canceled - the pending-state filter in
        // Requestable::cancelRequest gates on state, not just
        // canceled_at.
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $request = CheckoutRequest::factory()->create([
            'user_id' => $user->id,
            'requestable_id' => $asset->id,
            'requestable_type' => Asset::class,
        ]);
        FulfillCheckoutRequestAction::run($request);

        $affected = $asset->cancelRequest($user->id);

        $this->assertSame(0, $affected);
        $this->assertSame(CheckoutRequestState::Fulfilled, $request->fresh()->state);
    }

    public function test_open_scope_excludes_terminal_states(): void
    {
        $asset = Asset::factory()->create();
        $accessory = Accessory::factory()->create();

        // Pending: counts as open.
        CheckoutRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'requestable_id' => $asset->id,
            'requestable_type' => Asset::class,
        ]);
        // Fulfilled: does not count as open.
        $fulfilled = CheckoutRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'requestable_id' => $asset->id,
            'requestable_type' => Asset::class,
        ]);
        FulfillCheckoutRequestAction::run($fulfilled);
        // Canceled: does not count as open.
        $canceledUser = User::factory()->create();
        CheckoutRequest::factory()->create([
            'user_id' => $canceledUser->id,
            'requestable_id' => $accessory->id,
            'requestable_type' => Accessory::class,
        ]);
        $accessory->cancelRequest($canceledUser->id);

        $this->assertSame(1, CheckoutRequest::open()->count());
    }

    public function test_is_requested_by_returns_null_for_fulfilled_row(): void
    {
        // Requestable::isRequestedBy is what gates the "you already
        // have an open request" duplicate check + the request/
        // cancel button-swap on the requestable-tab. A fulfilled row
        // must not count so the user can request the same item
        // again after their previous request closed.
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        $request = CheckoutRequest::factory()->create([
            'user_id' => $user->id,
            'requestable_id' => $asset->id,
            'requestable_type' => Asset::class,
        ]);
        FulfillCheckoutRequestAction::run($request);

        $this->assertNull($asset->isRequestedBy($user));
    }
}
