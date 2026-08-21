<?php

namespace Tests\Feature\Requests\Ui;

use App\Models\User;
use Tests\TestCase;

class AssetRequestIndexTest extends TestCase
{
    public function test_requires_permission_to_view_asset_request_index()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('requests.index'))
            ->assertForbidden();
    }

    public function test_privileged_user_gets_the_shell_page()
    {
        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('requests.index'))
            ->assertOk();
    }
}
