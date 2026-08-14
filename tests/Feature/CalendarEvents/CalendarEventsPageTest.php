<?php

namespace Tests\Feature\CalendarEvents;

use App\Models\User;
use Tests\TestCase;

class CalendarEventsPageTest extends TestCase
{
    public function test_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('calendar.index'))
            ->assertOk();
    }

    public function test_requires_permission()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('calendar.index'))
            ->assertForbidden();
    }
}
