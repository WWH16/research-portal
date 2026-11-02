<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * An email that fails all its tries is told to the admins in the portal, at most once an hour.
 */
class EmailFailedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * As the queue worker reports a job that failed its last try.
     */
    private function jobFails(string $job): void
    {
        $payload = json_encode(['displayName' => $job, 'data' => ['commandName' => $job, 'command' => '']]);

        event(new JobFailed('database', new SyncJob(app(), $payload, 'database', 'default'), new RuntimeException('Connection could not be established.')));
    }

    public function test_admins_hear_once_an_hour_when_emails_fail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $faculty = User::factory()->create();

        $this->jobFails(SendQueuedNotifications::class);
        $this->jobFails(SendQueuedNotifications::class);

        $this->assertSame(1, $admin->notifications()->where('type', EmailFailed::class)->count());
        $this->assertSame(0, $faculty->notifications()->count());

        $this->travel(61)->minutes();
        $this->jobFails(SendQueuedNotifications::class);

        $this->assertSame(2, $admin->notifications()->where('type', EmailFailed::class)->count());
    }

    public function test_a_failed_job_that_is_not_an_email_tells_nobody(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->jobFails('App\Jobs\SomethingElse');

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_the_notice_says_what_to_do_and_is_never_emailed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertSame(['database'], (new EmailFailed)->via($admin));

        $this->jobFails(SendQueuedNotifications::class);
        $this->actingAs($admin);

        Livewire::test('pages::notifications.index')
            ->assertSee('Email not sent')
            ->assertSee('could not be sent after 3 tries');
    }
}
