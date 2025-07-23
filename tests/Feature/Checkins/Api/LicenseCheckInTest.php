<?php
namespace Tests\Feature\Checkins\Api;

use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LicenseCheckInTest extends TestCase {
    public function testLicenseCheckin()
    {
        $authUser = User::factory()->superuser()->create();
        $this->actingAsForApi($authUser);

        $license = License::factory()->create();
        $oldUser = User::factory()->create();
        Log::error("Right after create");
        Log::error(print_r($license->assetlog()->pluck('action_type', 'note')->toArray(), true));
        $licenseSeat = LicenseSeat::factory()->for($license)->create([
            'assigned_to' => $oldUser->id,
            'notes'       => 'Previously checked out',
        ]);
        Log::error("After making the seat, it's:");
        Log::error(print_r($license->assetlog()->pluck('action_type', 'note')->toArray(), true));

        $payload = [
            'assigned_to' => null,
            'asset_id'  => null,
            'notes' => 'Checking in the seat',
        ];

        $response = $this->patchJson(
            route('api.licenses.seats.update', [$license->id, $licenseSeat->id]),
            $payload);
        Log::error("after updating the seat:");
        Log::error(print_r($license->assetlog()->pluck('action_type', 'note')->toArray(), true));

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => 'success',
            ]);

        $licenseSeat->refresh();

        $this->assertNull($licenseSeat->assigned_to);
        $this->assertNull($licenseSeat->asset_id);

        $this->assertEquals('Checking in the seat', $licenseSeat->notes);
        $this->assertHasTheseActionLogs($license, ['add seats', 'create', 'checkin from']); //FIXME - bad order!
    }
}