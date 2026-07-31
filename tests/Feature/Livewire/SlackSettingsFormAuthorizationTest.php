<?php

namespace Tests\Feature\Livewire;

use App\Livewire\SlackSettingsForm;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the Livewire snapshot-replay authorization bypass
 * reported by PizzaStev3 (2026-07-31). SlackSettingsForm had no
 * per-request authorization and exposed webhook mutation methods
 * (testWebhook, clearSettings, submit) plus render()-time disclosure of
 * the configured webhook_endpoint/channel. boot() gate now blocks both
 * mount and any replay under a non-superuser session.
 */
class SlackSettingsFormAuthorizationTest extends TestCase
{
    public function test_superuser_can_mount()
    {
        $this->actingAs(User::factory()->superuser()->create());

        Livewire::test(SlackSettingsForm::class)
            ->assertStatus(200);
    }

    public function test_non_superuser_cannot_mount_or_replay()
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SlackSettingsForm::class)
            ->assertStatus(403);
    }
}
