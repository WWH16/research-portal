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
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToMoveFile;
use Masbug\Flysystem\GoogleDriveAdapter;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

    public function test_google_calls_have_time_limits(): void
    {
        $http = (new GoogleDrive)->client()->getHttpClient();

        $this->assertGreaterThan(0, $http->getConfig('connect_timeout'));
        $this->assertGreaterThan(0, $http->getConfig('timeout'));

        // The rest of Google's default stays: its address, errors as responses, and the stack disk() adds to.
        $this->assertSame((new Client)->getConfig('base_path'), (string) $http->getConfig('base_uri'));
        $this->assertFalse($http->getConfig('http_errors'));
        $this->assertInstanceOf(HandlerStack::class, $http->getConfig('handler'));
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

    /**
     * One folder's contents as Google's batch endpoint answers them.
     *
     * @param  list<array<string, mixed>>  $files
     */
    private function listing(array $files): Response
    {
        return new Response(200, ['Content-Type' => 'multipart/mixed; boundary=b'], "--b\r\nContent-Type: application/http\r\nContent-ID: response-1\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n".json_encode(['files' => $files])."\r\n--b--");
    }

    public function test_a_document_in_nested_folders_is_found_by_its_saved_path(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'root-folder']);
        $path = "drive-{$account->id}/Research Drive/CCS/Soil Study (m2p7)/Concept proposal (a1b2).pdf";

        // Sign-in, one listing per level, then the download: walking the folders any other way runs out of answers.
        $this->googleAnswers([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token": "fresh-access-token", "expires_in": 3599}'),
            $this->listing([$this->driveItem('a', 'Research Drive', 'root-folder')]),
            $this->listing([$this->driveItem('b', 'CCS', 'a')]),
            $this->listing([$this->driveItem('c', 'Soil Study (m2p7)', 'b')]),
            $this->listing([$this->driveItem('file-456', 'Concept proposal (a1b2).pdf', 'c', 'application/pdf')]),
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Length' => '9'], 'pdf-bytes'),
        ]);

        $response = app(DocumentStorage::class)->open($path, 'soil-study-concept.pdf');

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('file-456', Cache::get(DocumentStorage::fileIdKey($path)));
    }

    public function test_a_document_missing_from_google_drive_answers_not_found(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'root-folder']);
        $this->googleAnswers([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token": "fresh-access-token", "expires_in": 3599}'),
            $this->listing([]),
        ]);

        $this->expectException(NotFoundHttpException::class);

        app(DocumentStorage::class)->respond("drive-{$account->id}/Research Drive/CCS/Gone (aaaa)/Concept proposal (a1b2).pdf", 'gone.pdf');
    }

    public function test_a_new_project_folder_is_created_level_by_level(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'root-folder']);
        [$drive, $college, $project] = [$this->driveItem('a', 'Research Drive', 'root-folder'), $this->driveItem('b', 'CCS', 'a'), $this->driveItem('c', 'Soil Study (m2p7)', 'b')];

        // Sign-in; the empty Drive looked at twice; each folder made, then listed before the next; the upload; its permissions.
        $this->googleAnswers([
            $this->driveJson(['access_token' => 'fresh-access-token', 'expires_in' => 3599]),
            $this->listing([]),
            $this->listing([]),
            $this->driveJson($drive),
            $this->listing([$drive]),
            $this->driveJson($college),
            $this->listing([$college]),
            $this->driveJson($project),
            $this->listing([$project]),
            $this->driveJson($this->driveItem('file-456', 'Concept proposal (a1b2).pdf', 'c', 'application/pdf')),
            $this->driveJson(['permissions' => []]),
        ]);

        $path = app(DocumentStorage::class)->store(UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'), 'Research Drive/CCS/Soil Study (m2p7)', 'Concept proposal (a1b2)');

        $this->assertSame("drive-{$account->id}/Research Drive/CCS/Soil Study (m2p7)/Concept proposal (a1b2).pdf", $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function driveItem(string $id, string $name, string $parent, string $mime = 'application/vnd.google-apps.folder'): array
    {
        return ['id' => $id, 'name' => $name, 'mimeType' => $mime, 'parents' => [$parent], 'modifiedTime' => '2026-11-09T00:00:00.000Z'];
    }

    private function driveJson(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * Google's answers for moving submissions/old.pdf into an existing project folder, up to the move: sign-in, the walk
     * to the file, then the walk to the folder it is going to.
     *
     * @return list<Response>
     */
    private function answersBeforeTheMove(): array
    {
        return [
            $this->driveJson(['access_token' => 'fresh-access-token', 'expires_in' => 3599]),
            $this->listing([$this->driveItem('s', 'submissions', 'root-folder'), $this->driveItem('a', 'Research Drive', 'root-folder')]),
            $this->listing([$this->driveItem('file-456', 'old.pdf', 's', 'application/pdf')]),
            $this->listing([$this->driveItem('b', 'CCS', 'a')]),
            $this->listing([$this->driveItem('c', 'Soil Study (m2p7)', 'b')]),
            $this->listing([]),
        ];
    }

    /**
     * What Google answers when the portal looks for the moved file again, one listing per level.
     *
     * @return list<Response>
     */
    private function answersFindingTheFolder(array $filesInIt): array
    {
        return [
            $this->listing([$this->driveItem('a', 'Research Drive', 'root-folder')]),
            $this->listing([$this->driveItem('b', 'CCS', 'a')]),
            $this->listing([$this->driveItem('c', 'Soil Study (m2p7)', 'b')]),
            $this->listing($filesInIt),
        ];
    }

    public function test_a_document_moves_into_its_project_folder(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'root-folder']);
        $moved = $this->driveItem('file-456', 'Concept proposal (a1b2).pdf', 'c', 'application/pdf');
        $to = "drive-{$account->id}/Research Drive/CCS/Soil Study (m2p7)/Concept proposal (a1b2).pdf";
        $this->googleAnswers([...$this->answersBeforeTheMove(), $this->driveJson($moved), $this->driveJson($moved), $this->driveJson(['permissions' => []])]);

        app(DocumentStorage::class)->move("drive-{$account->id}/submissions/old.pdf", $to);

        // Google has no answers left, so this comes from the time the move carried over.
        $this->assertSame(strtotime('2026-11-09T00:00:00.000Z'), app(DocumentStorage::class)->uploadedAt($to)->timestamp);
    }

    public function test_a_move_google_finished_counts_even_when_the_read_after_it_fails(): void
    {
        Exceptions::fake();
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'root-folder']);
        $moved = $this->driveItem('file-456', 'Concept proposal (a1b2).pdf', 'c', 'application/pdf');
        $to = "drive-{$account->id}/Research Drive/CCS/Soil Study (m2p7)/Concept proposal (a1b2).pdf";
        $this->googleAnswers([
            ...$this->answersBeforeTheMove(),
            $this->driveJson($moved),
            new Response(500, ['Content-Type' => 'application/json'], '{"error": {"code": 500, "message": "Internal Error"}}'),
            ...$this->answersFindingTheFolder([$moved]),
        ]);

        app(DocumentStorage::class)->move("drive-{$account->id}/submissions/old.pdf", $to);

        $this->assertSame(strtotime('2026-11-09T00:00:00.000Z'), app(DocumentStorage::class)->uploadedAt($to)->timestamp);
        Exceptions::assertReportedCount(1);
    }

    public function test_a_move_that_never_happened_is_still_a_failure(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'root-folder']);
        $this->googleAnswers([
            ...$this->answersBeforeTheMove(),
            new Response(500, ['Content-Type' => 'application/json'], '{"error": {"code": 500, "message": "Internal Error"}}'),
            ...$this->answersFindingTheFolder([]),
        ]);

        $this->expectException(UnableToMoveFile::class);

        app(DocumentStorage::class)->move("drive-{$account->id}/submissions/old.pdf", "drive-{$account->id}/Research Drive/CCS/Soil Study (m2p7)/Concept proposal (a1b2).pdf");
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

    public function test_the_full_notice_survives_the_failed_save_that_found_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123']);
        $full = new Response(403, ['Content-Type' => 'application/json'], '{"error": {"code": 403, "errors": [{"reason": "storageQuotaExceeded"}]}}');
        $this->googleAnswers([new Response(200, ['Content-Type' => 'application/json'], '{"access_token": "fresh-access-token", "expires_in": 3599}'), $full]);

        // An upload saves inside a transaction, which rolls back when Google refuses the file.
        rescue(fn () => DB::transaction(function () use ($account) {
            app(GoogleDrive::class)->disk($account)->put('proposal.pdf', 'pdf');
            throw new RuntimeException('The document could not be stored.');
        }), report: false);

        $this->assertSame(1, $admin->notifications()->where('type', DriveFull::class)->count());
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
