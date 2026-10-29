<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ConceptAwaitingReview;
use App\Notifications\ConceptReviewed;
use App\Notifications\SignupAwaitingApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TidyNotificationNamesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A notice as it was saved before names were written short.
     */
    private function oldNotice(User $to, string $type, string $message): string
    {
        return $to->notifications()->create(['id' => (string) Str::uuid(), 'type' => $type, 'data' => ['message' => $message, 'url' => '/reviews'], 'read_at' => now()])->id;
    }

    public function test_names_in_sent_notices_are_shortened_and_nothing_else_changes(): void
    {
        User::factory()->create(['name' => 'JOHN ANSLEY ROCEL']);
        User::factory()->create(['name' => 'Juan Dela Cruz', 'email' => 'juan@isu.edu.ph']);
        $admin = User::factory()->create(['role' => 'admin']);

        $concept = $this->oldNotice($admin, ConceptAwaitingReview::class, 'New concept proposal to review: Mining Feedback, from JOHN ANSLEY ROCEL.');
        $signUp = $this->oldNotice($admin, SignupAwaitingApproval::class, 'New sign-up waiting for approval: Juan Dela Cruz (juan@isu.edu.ph).');
        $short = $this->oldNotice($admin, ConceptAwaitingReview::class, 'New concept proposal to review: Mining Feedback, from JA Rocel.');
        $other = $this->oldNotice($admin, ConceptReviewed::class, 'Concept proposal passed: JOHN ANSLEY ROCEL Study.');
        $gone = $this->oldNotice($admin, ConceptAwaitingReview::class, 'New concept proposal to review: Old Study, from A DELETED MEMBER.');

        $this->artisan('notifications:tidy-names')->expectsOutputToContain('2 notices')->assertSuccessful();

        $message = fn (string $id) => $admin->notifications()->find($id)->data['message'];
        $this->assertSame('New concept proposal to review: Mining Feedback, from JA Rocel.', $message($concept));
        $this->assertSame('New sign-up waiting for approval: JD Cruz (juan@isu.edu.ph).', $message($signUp));
        $this->assertSame('New concept proposal to review: Mining Feedback, from JA Rocel.', $message($short));
        $this->assertSame('Concept proposal passed: JOHN ANSLEY ROCEL Study.', $message($other));
        $this->assertSame('New concept proposal to review: Old Study, from A DELETED MEMBER.', $message($gone));
        // Only the wording changes: read state and link stay.
        $this->assertNotNull($admin->notifications()->find($concept)->read_at);
        $this->assertSame('/reviews', $admin->notifications()->find($concept)->data['url']);
    }

    public function test_a_dry_run_lists_the_changes_without_saving_them(): void
    {
        User::factory()->create(['name' => 'JOHN ANSLEY ROCEL']);
        $admin = User::factory()->create(['role' => 'admin']);
        $id = $this->oldNotice($admin, ConceptAwaitingReview::class, 'New concept proposal to review: Mining Feedback, from JOHN ANSLEY ROCEL.');

        $this->artisan('notifications:tidy-names', ['--dry-run' => true])
            ->expectsOutputToContain('from JA Rocel.')
            ->expectsOutputToContain('Nothing was saved')
            ->assertSuccessful();

        $this->assertSame('New concept proposal to review: Mining Feedback, from JOHN ANSLEY ROCEL.', $admin->notifications()->find($id)->data['message']);
    }
}
