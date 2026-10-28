<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Models\User;
use App\Services\GoogleDrive;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Masbug\Flysystem\GoogleDriveAdapter;
use Mockery\MockInterface;
use Tests\TestCase;

class GoogleDriveDiskTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    private function project(array $paths): Submission
    {
        $project = $this->owner->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies'])->id,
            'title' => 'Stored Study',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
        ] + $paths);
        $project->proponents()->create(['user_id' => $this->owner->id, 'study' => 1, 'role' => 'Leader']);

        return $project;
    }

    /**
     * The real GoogleDrive, with Google's sign-in answering each token request in turn instead of the network.
     *
     * @param  list<Response>  $answers
     */
    private function googleAnswers(array $answers): void
    {
        $this->partialMock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('client')
            ->andReturnUsing(fn (?string $token) => tap((new GoogleDrive)->client($token), fn ($client) => $client->setHttpClient(
                // Google's own default: an error comes back as a response, not an exception.
                new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($answers)), 'http_errors' => false]),
            ))));
    }

    public function test_the_drive_disk_signs_in_as_the_saved_account_once_per_request(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123', 'refresh_token' => 'saved-token']);
        // One answer only: a second sign-in would find none and fail.
        $this->googleAnswers([new Response(200, ['Content-Type' => 'application/json'], '{"access_token": "fresh-access-token", "expires_in": 3599}')]);

        $disk = app(GoogleDrive::class)->disk($account);

        $this->assertInstanceOf(GoogleDriveAdapter::class, $disk->getAdapter());
        // Never "public": the adapter would share a public file with anyone who has the link.
        $this->assertSame('private', $disk->getConfig()['visibility']);
        $this->assertSame($disk, app(GoogleDrive::class)->disk($account));
    }

    public function test_opening_a_document_on_a_disconnected_account_says_storage_is_down(): void
    {
        $account = GoogleDriveConnection::factory()->unconnected()->create();
        $project = $this->project(['concept_path' => "drive-{$account->id}/submissions/concept.pdf"]);

        $this->actingAs($this->owner)
            ->get(route('submissions.document', [$project, 'concept']))
            ->assertStatus(503)
            ->assertSee('Document storage is unavailable right now.');
    }

    public function test_opening_a_document_after_google_refuses_the_token_says_storage_is_down(): void
    {
        $account = GoogleDriveConnection::factory()->create();
        $project = $this->project(['concept_path' => "drive-{$account->id}/submissions/concept.pdf"]);
        $this->googleAnswers([new Response(400, ['Content-Type' => 'application/json'], '{"error": "invalid_grant", "error_description": "Token has been expired or revoked."}')]);

        $this->actingAs($this->owner)
            ->get(route('submissions.document', [$project, 'concept']))
            ->assertStatus(503)
            ->assertSee('Document storage is unavailable right now.');

        // Google said the access is gone, so the Google Drive page now asks an admin to connect again.
        $this->assertFalse($account->fresh()->isConnected());
    }

    public function test_a_document_opens_from_the_account_that_holds_it(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'retired_at' => now()]);
        GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);
        $drive = Storage::fake('drive');
        $drive->put('submissions/concept.pdf', 'from email1');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')
            ->withArgs(fn (GoogleDriveConnection $holder) => $holder->is($email1))->andReturn($drive));
        $project = $this->project(['concept_path' => "drive-{$email1->id}/submissions/concept.pdf"]);

        $this->actingAs($this->owner)
            ->get(route('submissions.document', [$project, 'concept']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_documents_download_with_the_type_of_their_extension(): void
    {
        Storage::fake('submissions');
        Storage::disk('submissions')->put('submissions/concept.pdf', 'pdf');
        Storage::disk('submissions')->put('submissions/detailed.doc', 'doc');
        Storage::disk('submissions')->put('submissions/midyear.docx', 'docx');
        $project = $this->project([
            'concept_path' => 'submissions/concept.pdf',
            'detailed_path' => 'submissions/detailed.doc',
            'midyear_path' => 'submissions/midyear.docx',
        ]);

        $this->actingAs($this->owner);

        $this->get(route('submissions.document', [$project, 'concept']))->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('submissions.document', [$project, 'detailed']))->assertHeader('Content-Type', 'application/msword');
        $this->get(route('submissions.document', [$project, 'midyear']))->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
}
