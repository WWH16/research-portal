<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_schedule_beats_every_five_minutes(): void
    {
        $this->travelTo('2026-11-02 10:05:00');

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertSame(now()->timestamp, Cache::get('heartbeat.scheduler'));
        $this->assertSame(now()->timestamp, Cache::get('heartbeat.worker'));
    }

    public function test_nothing_is_wrong_before_the_first_beat_or_while_both_keep_beating(): void
    {
        $this->assertNull(Heartbeat::issue());

        Heartbeat::beat();
        $this->travel(14)->minutes();

        $this->assertNull(Heartbeat::issue());
    }

    public function test_a_worker_that_stopped_or_never_started_is_reported(): void
    {
        Queue::fake();
        Heartbeat::beat();
        $this->travel(16)->minutes();
        Heartbeat::beat();

        $this->assertStringContainsString('queue worker', Heartbeat::issue());
    }

    public function test_a_stopped_scheduler_is_reported_first(): void
    {
        Heartbeat::beat();
        $this->travel(16)->minutes();

        $this->assertStringContainsString('scheduler', Heartbeat::issue());
    }

    public function test_only_admins_see_the_warning(): void
    {
        Heartbeat::beat();
        $this->travel(16)->minutes();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertSee('The scheduler has stopped');

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertDontSee('The scheduler has stopped');
    }
}
