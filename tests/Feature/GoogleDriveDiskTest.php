<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\DriveFull;
use App\Services\DocumentStorage;
use App\Services\GoogleDrive;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
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

    public function test_later_requests_reuse_the_access_token_until_google_refuses_it(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123', 'refresh_token' => 'saved-token']);
        $token = fn (string $value) => new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => $value, 'expires_in' => 3599]));
        $accessToken = fn () => app(GoogleDrive::class)->disk($account)->getAdapter()->getService()->getClient()->getAccessToken()['access_token'];

        $this->googleAnswers([$token('first-token'), new Response(401, ['Content-Type' => 'application/json'], '{"error": {"code": 401, "message": "Invalid Credentials"}}')]);
        $this->assertSame('first-token', $accessToken());

        // A later request signs in no more: it has no answer for a sign-in, and keeps the token from before.
        $this->googleAnswers([new Response(401, ['Content-Type' => 'application/json'], '{"error": {"code": 401, "message": "Invalid Credentials"}}')]);
        $service = app(GoogleDrive::class)->disk($account)->getAdapter()->getService();
        $this->assertSame('first-token', $service->getClient()->getAccessToken()['access_token']);

        // Google refusing the token, as after someone removes the portal's access, drops it, so the next request signs in again
        // and learns whether the account is still reachable.
        rescue(fn () => $service->files->get('folder-123'), report: false);
        $this->googleAnswers([$token('second-token')]);
        $this->assertSame('second-token', $accessToken());
    }

    public function test_a_document_opened_before_downloads_straight_from_its_saved_file_id(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123']);
        $path = "drive-{$account->id}/submissions/proposal.pdf";
        Cache::forever(DocumentStorage::fileIdKey($path), 'file-456');
        // Sign-in and one download, nothing more: finding the file by name again would find no answer and fail.
        $this->googleAnswers([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token": "fresh-access-token", "expires_in": 3599}'),
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Length' => '9'], 'pdf-bytes'),
        ]);

        $response = app(DocumentStorage::class)->open($path, 'solar-dryer-concept.pdf');

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline; filename=solar-dryer-concept.pdf', $response->headers->get('Content-Disposition'));
        ob_start();
        $response->sendContent();
        $this->assertSame('pdf-bytes', ob_get_clean());
    }

    public function test_an_image_has_drives_smaller_copy_once_drive_has_made_it(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123']);
        $path = "drive-{$account->id}/announcements/poster.png";
        Cache::forever(DocumentStorage::fileIdKey($path), 'file-456');
        $sent = fn ($response) => tap(ob_start(), fn () => $response->sendContent()) ? ob_get_clean() : '';
        $token = new Response(200, ['Content-Type' => 'application/json'], '{"access_token": "fresh-access-token", "expires_in": 3599}');
        $thumbnailLink = new Response(200, ['Content-Type' => 'application/json'], '{"thumbnailLink": "https://lh3.googleusercontent.com/drive-storage/abc=s220"}');

        $this->googleAnswers([$token, $thumbnailLink, new Response(200, ['Content-Type' => 'image/jpeg'], 'small-jpeg')]);
        $response = app(DocumentStorage::class)->thumbnail($path, 'poster.png', 1000);
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('small-jpeg', $sent($response));

        // Drive has no copy yet, as just after an upload: there is none, and the caller sends the file. The token from before still holds.
        $this->googleAnswers([$thumbnailLink, new Response(404, [], 'Not Found'), new Response(200, ['Content-Type' => 'image/png'], 'full-png')]);
        $this->assertNull(app(DocumentStorage::class)->thumbnail($path, 'poster.png', 1000));
        $response = app(DocumentStorage::class)->respond($path, 'poster.png');
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('full-png', $sent($response));
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
        $drive = $this->fakeDrive();
        $drive->put('submissions/concept.pdf', 'pdf');
        $drive->put('submissions/detailed.doc', 'doc');
        $drive->put('submissions/midyear.docx', 'docx');
        $project = $this->project([
            'concept_path' => 'drive-1/submissions/concept.pdf',
            'detailed_path' => 'drive-1/submissions/detailed.doc',
            'midyear_path' => 'drive-1/submissions/midyear.docx',
        ]);

        $this->actingAs($this->owner);

        $this->get(route('submissions.document', [$project, 'concept']))->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('submissions.document', [$project, 'detailed']))->assertHeader('Content-Type', 'application/msword');
        $this->get(route('submissions.document', [$project, 'midyear']))->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_usage_reads_the_accounts_google_storage(): void
    {
        $account = GoogleDriveConnection::factory()->create();
        $this->googleAnswers([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token": "fresh-access-token", "expires_in": 3599}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"id": "folder-123"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"storageQuota": {"limit": "16106127360", "usage": "4509715660", "usageInDrive": "3000000000"}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"files": [{"size": "1000"}, {"size": "2500"}]}'),
        ]);

        $this->assertSame(['used' => 4509715660, 'limit' => 16106127360, 'drive' => 3000000000, 'portal' => 3500, 'pooled' => false], app(GoogleDrive::class)->usage($account));
    }

    public function test_an_upload_google_refuses_for_lack_of_space_marks_the_account_full_and_tells_admins_once(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $faculty = User::factory()->create();
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123', 'email' => 'office@isu.edu.ph']);
        $full = new Response(403, ['Content-Type' => 'application/json'], '{"error": {"code": 403, "errors": [{"reason": "storageQuotaExceeded"}]}}');
        $this->googleAnswers([new Response(200, ['Content-Type' => 'application/json'], '{"access_token": "fresh-access-token", "expires_in": 3599}'), $full, $full]);

        $disk = app(GoogleDrive::class)->disk($account);
        // The adapter swallows Google's error and only reports a failed write, so the refusal is read off the response.
        $this->assertFalse($disk->put('proposal.pdf', 'pdf'));
        $disk->put('proposal.pdf', 'pdf');

        $this->assertTrue(Cache::has($account->fullKey()));
        Notification::assertSentToTimes($admin, DriveFull::class, 1);
        Notification::assertSentTo($admin, DriveFull::class, fn (DriveFull $notification) => $notification->toArray($admin) === [
            'message' => 'Google Drive office@isu.edu.ph is full, so documents can’t be uploaded. Free up space in it, or change the storage account on System → Google Drive.',
            'url' => route('google-drive.index'),
        ]);
        Notification::assertNotSentTo($faculty, DriveFull::class);
    }

    public function test_a_school_account_reports_its_own_drive_not_its_organizations_pool(): void
    {
        $account = GoogleDriveConnection::factory()->create();
        // Google names the account's organization (hd) in the ID token it sends with the access token.
        $idToken = 'header.'.rtrim(strtr(base64_encode(json_encode(['email' => 'office@isu.edu.ph', 'hd' => 'isu.edu.ph'])), '+/', '-_'), '=').'.signature';
        $this->googleAnswers([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'fresh-access-token', 'expires_in' => 3599, 'id_token' => $idToken])),
            new Response(200, ['Content-Type' => 'application/json'], '{"id": "folder-123"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"storageQuota": {"limit": "217331566661632", "usage": "135151520000000", "usageInDrive": "497000000"}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"files": [{"size": "209000"}]}'),
        ]);

        $this->assertSame(['used' => 497000000, 'limit' => null, 'drive' => 497000000, 'portal' => 209000, 'pooled' => true], app(GoogleDrive::class)->usage($account));
    }
}
