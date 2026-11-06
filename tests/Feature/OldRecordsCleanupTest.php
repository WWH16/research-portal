<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OldRecordsCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_older_than_twelve_months_are_deleted(): void
    {
        $user = User::factory()->create();
        $notify = fn () => $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => []]);

        $this->travelTo(now()->subMonths(12)->subDay());
        $notify();
        $this->travelBack();
        $recent = $notify();

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertSame([$recent->id], $user->notifications()->pluck('id')->all());
    }

    public function test_failed_email_records_older_than_twelve_months_are_deleted(): void
    {
        $fail = fn ($at) => DB::table('failed_jobs')->insert(['uuid' => Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => $at]);
        $fail(now()->subMonths(12)->subDays(2));
        $fail(now()->subMonths(11));

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'queue:prune-failed'));
        $this->assertStringContainsString('--hours=8760', $event->command);

        $this->artisan('queue:prune-failed', ['--hours' => 8760])->assertSuccessful();

        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_the_notification_cleanup_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'notifications:prune'));

        $this->assertSame('0 0 * * *', $event->expression);
    }
}
