# Google Drive Project Folders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Save each new project document on Google Drive under `Research Drive/<college code>/<project title> (<code>)/<document name> (<code>).<ext>` instead of one flat `submissions` folder with random names.

**Architecture:** `Submission` decides folder and file names. `DocumentStorage::store()` gains an optional file name. The project form computes one folder per save and passes it to `store()`. The saved `*_path` columns keep their `drive-<account>/<location>` shape, so opening, downloading and deleting need no change.

**Tech Stack:** Laravel 13, Livewire 4 single-file pages, `masbug/flysystem-google-drive-ext` 2.5, MySQL, Pest running PHPUnit-style test classes.

**Spec:** No separate file. The design was approved in chat on 2026-11-09 and is restated under Design below.

## Design

Folder layout, without year folders:

```
Faculty Research Portal/
├── Research Drive/
│   └── CCS/
│       └── Flood Mapping in Cauayan (k3f9)/
│           ├── Concept proposal (a1b2).pdf
│           └── Detailed proposal (c3d4).pdf
├── announcements/
├── profile-images/
└── submissions/          older files only
```

- Later documents of a project join that project's existing folder.
- A replaced document gets a new file in the same folder. The old file goes to Drive trash, as it does now.
- Portal pages, permissions, downloads, `announcements` and `profile-images` do not change.
- Existing files stay where they are and keep opening. An older project gets a folder at its next upload.
- Saving the form again after a storage failure reuses the folder the first try made.
- Renaming a project or a college does not rename its Drive folder.
- No new package, table or migration.

Known limits, accepted:

- A college folder appears only after that college's first upload.
- Two first uploads for one college in the same moment, or one Drive listing that fails during an upload, can create a second folder with the same name. The adapter swallows a failed listing and treats the folder as empty. Files open from either folder, and each duplicate costs one more listing per lookup.
- A save that fails after a new folder was made leaves that folder empty until the form is saved again. An abandoned form leaves it empty for good.
- The adapter lists a folder straight after creating it. If Drive has not caught up, that upload fails, the member sees the storage message, and saving again succeeds. The Release Check measures this on real Drive.
- Nobody should move or rename these files by hand in Drive.

## Global Constraints

- Work on branch `feat/drive-project-folders`, created from `main`.
- Never add a `Co-Authored-By` trailer or any AI attribution to a commit message.
- Tests use the fake disk or Guzzle answers only. Never upload through the local portal: the local `.env` is connected to a real Google account.
- Never run Pint on `⚡` page files. Run it only on the changed `app/` and `tests/` files.
- Never add a bucket or any disk other than Google Drive.
- Saved paths must fit the `varchar(255)` `*_path` columns.
- Folder and file names built from user text contain only `A-Z a-z 0-9`, space and hyphen. The adapter rewrites `/ \ ? % * : | " < > # @ ! $ & ' + ; = ^ ~` and backtick when it lists Drive, so a name holding one of those is never found again.
- Short codes are `Str::lower(Str::random(4))`, written as ` (k3f9)`.
- Every existing Feature test is a class that Pest runs, so new tests follow that style. Generate with `php artisan make:test <Name>` and without `--pest`.

## Review Focus

- A title with characters the adapter rewrites, such as `& : ' / %`, or with accents: the saved path holds only safe characters and the file can be found again. Tests in Tasks 1 and 3.
- A save retried after Google Drive failed: it reuses the folder the first try made, so retries don't pile up folders. Test in Task 3.
- A document missing from Google Drive, as after someone moves it by hand: the member gets a 404, not a 500. Test in Task 2.
- A project whose documents are on an earlier storage account: the next upload goes to the current account under the same folder name, and the old path stays. Test in Task 3.
- The longest allowed title and college code: the saved path still fits 255 characters. Test in Task 1.

---

### Task 1: Folder and file names on `Submission`

**Files:**
- Create: `tests/Feature/ProjectDriveFoldersTest.php`
- Modify: `app/Models/Submission.php` (add `use Illuminate\Support\Str;`; add the members below after `displayTitle()`)

**Interfaces:**
- Produces:
  - `Submission::DRIVE_ROOT = 'Research Drive'`
  - `Submission::newDriveFolder(string $college, string $title): string`
  - `Submission::driveDocumentName(string $stage): string`, with no extension
  - `$submission->driveFolder(): ?string`, null when no document is in a project folder yet

- [ ] **Step 1: Create the branch and the test class**

Run: `git switch -c feat/drive-project-folders`, then `php artisan make:test ProjectDriveFoldersTest`

- [ ] **Step 2: Write the failing tests**

```php
use RefreshDatabase;

private const CODE = '\([a-z0-9]{4}\)';

public function test_a_new_folder_reads_college_then_title_then_a_short_code(): void
{
    $this->assertMatchesRegularExpression('#^Research Drive/CCS/Flood Mapping in Cauayan '.self::CODE.'$#', Submission::newDriveFolder('CCS', 'Flood Mapping in Cauayan'));
}

public function test_a_document_is_named_after_its_stage_with_a_short_code(): void
{
    $this->assertMatchesRegularExpression('#^Mid-year progress report '.self::CODE.'$#', Submission::driveDocumentName('midyear'));
}

public function test_folder_names_keep_only_characters_a_drive_lookup_can_match(): void
{
    $this->assertMatchesRegularExpression('#^Research Drive/CCS/Rice Corn A Study Anos 100 '.self::CODE.'$#', Submission::newDriveFolder('CCS', 'Rice & Corn: A "Study" / Año’s 100%?'));
}

public function test_underscores_in_a_title_read_as_spaces(): void
{
    $this->assertMatchesRegularExpression('#/SMART Research Track '.self::CODE.'$#', Submission::newDriveFolder('CCS', 'SMART_Research_Track'));
}

public function test_a_title_or_college_with_no_usable_characters_gets_a_plain_name(): void
{
    $this->assertMatchesRegularExpression('#^Research Drive/College/Project '.self::CODE.'$#', Submission::newDriveFolder('研究', '研究'));
}

public function test_two_projects_with_the_same_title_get_different_folders(): void
{
    $this->assertNotSame(Submission::newDriveFolder('CCS', 'Soil Study'), Submission::newDriveFolder('CCS', 'Soil Study'));
}

public function test_the_longest_title_and_college_fit_the_path_column(): void
{
    $path = 'drive-999999/'.Submission::newDriveFolder(str_repeat('C', 20), str_repeat('a', 255)).'/'.Submission::driveDocumentName('midyear').'.docx';

    $this->assertLessThanOrEqual(255, strlen($path));
}

public function test_a_project_reports_the_folder_its_documents_are_in(): void
{
    $project = new Submission([
        'concept_path' => 'drive-1/submissions/old.pdf',
        'detailed_path' => 'drive-2/Research Drive/CCS/Soil Study (m2p7)/Detailed proposal (c3d4).pdf',
    ]);

    $this->assertSame('Research Drive/CCS/Soil Study (m2p7)', $project->driveFolder());
}

public function test_a_project_with_only_flat_files_has_no_folder_yet(): void
{
    $this->assertNull((new Submission(['concept_path' => 'drive-1/submissions/old.pdf']))->driveFolder());
    $this->assertNull((new Submission)->driveFolder());
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --filter=ProjectDriveFoldersTest`
Expected: FAIL with an undefined method `newDriveFolder`.

- [ ] **Step 4: Implement the members in `app/Models/Submission.php`**

Add `public const DRIVE_ROOT = 'Research Drive';` beside `DOCUMENTS`.

`newDriveFolder()` returns `DRIVE_ROOT/<clean college>/<clean title> (<code>)`. The fallbacks are `College` and `Project`. Its docblock carries this line: `ponytail: two first uploads for a college at once can make two same-named folders; files still open from either. Lock the folder creation if that ever shows up.`

`driveDocumentName($stage)` returns `self::DOCUMENTS[$stage].' ('.<code>.')'`.

`driveFolder()` reads the four `<stage>_path` columns from `self::DOCUMENTS`, drops the `drive-<n>/` prefix with `Str::after($path, '/')`, takes the first location starting with `DRIVE_ROOT.'/'`, and returns it through `Str::beforeLast($location, '/')`, or null.

A private static `driveName(string $text, string $fallback): string` does the cleaning, and a private static `driveCode(): string` makes the code:

```php
$name = (string) Str::of(str_replace('_', ' ', $text))->ascii()->replaceMatches('/[^A-Za-z0-9 \-]/', '')->squish()->limit(100, '')->trim();

return $name !== '' ? $name : $fallback;
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=ProjectDriveFoldersTest`
Expected: PASS, 9 tests.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Models/Submission.php tests/Feature/ProjectDriveFoldersTest.php
git add app/Models/Submission.php tests/Feature/ProjectDriveFoldersTest.php
git commit -m "feat(drive): name project folders and documents on the model"
```

### Task 2: Named uploads and failure handling in `DocumentStorage`

**Files:**
- Modify: `app/Services/DocumentStorage.php:39-53` and `:194-205`
- Test: `tests/Feature/DocumentStorageTest.php`, `tests/Feature/GoogleDriveDiskTest.php`

**Interfaces:**
- Produces: `DocumentStorage::store(UploadedFile $file, string $folder = 'submissions', ?string $name = null): string`. `$name` has no extension. Omitting it keeps the random file name, so memos, photos and the existing `shouldReceive('store')` mocks are unaffected.

- [ ] **Step 1: Write the failing tests in `DocumentStorageTest`**

```php
public function test_a_named_document_is_saved_in_its_folder_under_that_name(): void
{
    $this->fakeDrives();
    $account = GoogleDriveConnection::factory()->create();

    $path = app(DocumentStorage::class)->store($this->pdf(), 'Research Drive/CCS/Soil Study (m2p7)', 'Concept proposal (a1b2)');

    $this->assertSame("drive-{$account->id}/Research Drive/CCS/Soil Study (m2p7)/Concept proposal (a1b2).pdf", $path);
    [$disk, $location] = app(DocumentStorage::class)->locate($path);
    $disk->assertExists($location);
}

public function test_a_named_document_google_refuses_is_reported_as_unavailable(): void
{
    Exceptions::fake();
    GoogleDriveConnection::factory()->create();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->andReturnFalse();
    $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')->andReturn($disk));

    try {
        app(DocumentStorage::class)->store($this->pdf(), 'Research Drive/CCS/Soil Study (m2p7)', 'Concept proposal (a1b2)');
        $this->fail('The upload should have failed.');
    } catch (DocumentStorageUnavailable) {
        Exceptions::assertReported(DocumentStorageUnavailable::class);
    }
}

public function test_a_failed_upload_leaves_its_cause_in_the_log(): void
{
    Exceptions::fake();
    GoogleDriveConnection::factory()->create();
    $this->failingDrive('Google is unavailable.');

    try {
        app(DocumentStorage::class)->store($this->pdf());
        $this->fail('The upload should have failed.');
    } catch (DocumentStorageUnavailable) {
        Exceptions::assertReported(RuntimeException::class);
    }
}
```

- [ ] **Step 2: Add the adapter tests to `GoogleDriveDiskTest`**

The fake disk cannot show how the real adapter walks folders. These tests answer in Google's real response shape and in the order the adapter asks. `in_nested_folders` passes before the change and guards against an adapter upgrade.

```php
/** One folder's contents as Google's batch endpoint answers them. */
private function listing(array $files): Response
{
    return new Response(200, ['Content-Type' => 'multipart/mixed; boundary=b'], "--b\r\nContent-Type: application/http\r\nContent-ID: response-1\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n".json_encode(['files' => $files])."\r\n--b--");
}

public function test_a_document_in_nested_folders_is_found_by_its_saved_path(): void
{
    $account = GoogleDriveConnection::factory()->create(['folder_id' => 'root-folder']);
    $path = "drive-{$account->id}/Research Drive/CCS/Soil Study (m2p7)/Concept proposal (a1b2).pdf";
    $item = fn (string $id, string $name, string $parent, string $mime = 'application/vnd.google-apps.folder') => ['id' => $id, 'name' => $name, 'mimeType' => $mime, 'parents' => [$parent], 'modifiedTime' => '2026-11-09T00:00:00.000Z'];

    // Sign-in, one listing per level, then the download: walking the folders any other way runs out of answers.
    $this->googleAnswers([
        new Response(200, ['Content-Type' => 'application/json'], '{"access_token": "fresh-access-token", "expires_in": 3599}'),
        $this->listing([$item('a', 'Research Drive', 'root-folder')]),
        $this->listing([$item('b', 'CCS', 'a')]),
        $this->listing([$item('c', 'Soil Study (m2p7)', 'b')]),
        $this->listing([$item('file-456', 'Concept proposal (a1b2).pdf', 'c', 'application/pdf')]),
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
    $json = fn (array $body) => new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    $item = fn (string $id, string $name, string $parent, string $mime = 'application/vnd.google-apps.folder') => ['id' => $id, 'name' => $name, 'mimeType' => $mime, 'parents' => [$parent], 'modifiedTime' => '2026-11-09T00:00:00.000Z'];
    [$drive, $college, $project] = [$item('a', 'Research Drive', 'root-folder'), $item('b', 'CCS', 'a'), $item('c', 'Soil Study (m2p7)', 'b')];

    // Sign-in; the empty Drive looked at twice; each folder made, then listed before the next; the upload; its permissions.
    $this->googleAnswers([
        $json(['access_token' => 'fresh-access-token', 'expires_in' => 3599]),
        $this->listing([]),
        $this->listing([]),
        $json($drive),
        $this->listing([$drive]),
        $json($college),
        $this->listing([$college]),
        $json($project),
        $this->listing([$project]),
        $json($item('file-456', 'Concept proposal (a1b2).pdf', 'c', 'application/pdf')),
        $json(['permissions' => []]),
    ]);

    $path = app(DocumentStorage::class)->store(UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'), 'Research Drive/CCS/Soil Study (m2p7)', 'Concept proposal (a1b2)');

    $this->assertSame("drive-{$account->id}/Research Drive/CCS/Soil Study (m2p7)/Concept proposal (a1b2).pdf", $path);
}
```

- [ ] **Step 3: Run the tests to verify the new behaviour fails**

Run: `php artisan test --filter="a_named_document|failed_upload_leaves|in_nested_folders|missing_from_google_drive|level_by_level"`
Expected: `in_nested_folders` PASSES. The other five FAIL.

- [ ] **Step 4: Implement the optional `$name` and the two failure fixes**

In `store()`, when `$name` is null keep `putFile($folder, $file)`. Otherwise call `putFileAs($folder, $file, rtrim($name.'.'.$file->guessExtension(), '.'))`. Update the docblock.

Log every failed upload, because the form only shows the member a message and nothing else records the cause. Add `report($e);` first in the `catch (Throwable $e)` block, and replace the `$location ?: throw` line, which is where a refused upload lands:

```php
if (! $location) {
    report($unavailable = new DocumentStorageUnavailable('The document could not be stored.'));

    throw $unavailable;
}
```

In `findFileId()`, catch `League\Flysystem\UnableToReadFile` around `$adapter->getMetadata($location)` and return null. Catch only that class, so a Google outage still answers 503.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter="DocumentStorageTest|GoogleDriveDiskTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Services/DocumentStorage.php tests/Feature/DocumentStorageTest.php tests/Feature/GoogleDriveDiskTest.php
git add app/Services/DocumentStorage.php tests/Feature/DocumentStorageTest.php tests/Feature/GoogleDriveDiskTest.php
git commit -m "feat(drive): name stored documents and report failed uploads"
```

### Task 3: File project uploads in their folders

**Files:**
- Modify: `resources/views/pages/submissions/⚡create.blade.php:233-236`, and add a property after `$opened`
- Modify: `docs/backup-and-restore.md:23` and its "Keeping it working" list
- Test: `tests/Feature/ProjectDriveFoldersTest.php`

**Interfaces:**
- Consumes: all of Task 1's `Submission` members, and Task 2's `store($file, $folder, $name)`.

- [ ] **Step 1: Add the fixture to the test class**

```php
private const FOLDER = 'Research Drive/CCS/Soil Study (m2p7)';

private FilesystemAdapter $drive;

private User $maria;

protected function setUp(): void
{
    parent::setUp();

    $this->drive = $this->fakeDrive();
    $college = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
    $this->maria = User::factory()->create(['department_id' => $college->id]);
    $this->actingAs($this->maria);
}

private function project(array $attributes): Submission
{
    $project = $this->maria->submissions()->create([
        'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
        'department_id' => $this->maria->department_id,
        'title' => 'Soil Study',
        'abstract' => 'An abstract.',
        'start_date' => now(),
        'target_date' => now()->addYear(),
        'awaiting_review' => false,
        'concept_passed' => true,
        ...$attributes,
    ]);
    $project->proponents()->create(['user_id' => $this->maria->id, 'study' => 1, 'role' => 'Leader']);

    return $project;
}

/** @param list<string> $stages */
private function upload(Submission $project, array $stages, ?string $title = null): void
{
    $form = Livewire::test('pages::submissions.create', ['submission' => $project->fresh()]);
    $title && $form->set('title', $title);

    foreach ($stages as $stage) {
        $form->set("documents.{$stage}", UploadedFile::fake()->create("{$stage}.pdf", 100, 'application/pdf'));
    }

    $form->call('save')->assertHasNoErrors();
}

private function filledForm(string $title): Testable
{
    return Livewire::test('pages::submissions.create')
        ->set('title', $title)
        ->set('abstract', 'An abstract.')
        ->set('category_id', Category::firstOrCreate(['name' => 'Computing'])->id)
        ->set('start_date', now()->toDateString())
        ->set('target_date', now()->addYear()->toDateString())
        ->set('documents.concept', UploadedFile::fake()->create('proposal.pdf', 500, 'application/pdf'));
}

private function newProject(string $title): string
{
    $this->filledForm($title)->call('save')->assertHasNoErrors();

    return Submission::sole()->concept_path;
}
```

- [ ] **Step 2: Write the failing tests**

```php
public function test_a_new_project_files_its_document_under_college_and_title(): void
{
    $path = $this->newProject('Flood Mapping in Cauayan');

    $this->assertMatchesRegularExpression('#^drive-1/Research Drive/CCS/Flood Mapping in Cauayan '.self::CODE.'/Concept proposal '.self::CODE.'\.pdf$#', $path);
    $this->drive->assertExists(Str::after($path, '/'));
}

public function test_an_unsafe_title_is_saved_with_safe_names_only(): void
{
    $this->assertMatchesRegularExpression('#^drive-1/Research Drive/CCS/Rice Corn A Study '.self::CODE.'/#', $this->newProject('Rice & Corn: A "Study"?'));
}

public function test_the_next_document_joins_the_project_folder(): void
{
    $project = $this->project(['concept_path' => 'drive-1/'.self::FOLDER.'/Concept proposal (a1b2).pdf']);

    $this->upload($project, ['detailed']);

    $this->assertMatchesRegularExpression('#^drive-1/Research Drive/CCS/Soil Study \(m2p7\)/Detailed proposal '.self::CODE.'\.pdf$#', $project->fresh()->detailed_path);
}

public function test_a_replaced_document_stays_in_its_folder_and_the_old_file_goes(): void
{
    $old = self::FOLDER.'/Concept proposal (a1b2).pdf';
    $this->drive->put($old, 'first version');
    $project = $this->project(['concept_path' => 'drive-1/'.$old]);

    $this->upload($project, ['concept']);

    $this->assertSame(self::FOLDER, $project->fresh()->driveFolder());
    $this->assertNotSame('drive-1/'.$old, $project->fresh()->concept_path);
    $this->drive->assertMissing($old);
    $this->assertCount(1, $this->drive->files(self::FOLDER));
}

public function test_an_older_project_gets_a_folder_at_its_next_upload_and_keeps_its_flat_file(): void
{
    $this->drive->put('submissions/old.pdf', 'old');
    $project = $this->project(['concept_path' => 'drive-1/submissions/old.pdf']);

    $this->upload($project, ['detailed']);

    $this->assertMatchesRegularExpression('#^drive-1/Research Drive/CCS/Soil Study '.self::CODE.'/Detailed proposal #', $project->fresh()->detailed_path);
    $this->assertSame('drive-1/submissions/old.pdf', $project->fresh()->concept_path);
    $this->drive->assertExists('submissions/old.pdf');
}

public function test_documents_saved_together_share_one_new_folder(): void
{
    $project = $this->project(['concept_path' => 'drive-1/submissions/a.pdf', 'detailed_path' => 'drive-1/submissions/b.pdf']);

    $this->upload($project, ['concept', 'detailed']);

    $project->refresh();
    $this->assertStringStartsWith('drive-1/Research Drive/CCS/Soil Study (', $project->concept_path);
    $this->assertSame(Str::beforeLast($project->concept_path, '/'), Str::beforeLast($project->detailed_path, '/'));
}

public function test_renaming_a_project_or_its_college_keeps_the_folder(): void
{
    $project = $this->project(['concept_path' => 'drive-1/'.self::FOLDER.'/Concept proposal (a1b2).pdf']);
    $this->maria->department->update(['code' => 'CICT']);

    $this->upload($project, ['detailed'], 'A New Title');

    $this->assertStringStartsWith('drive-1/'.self::FOLDER.'/', $project->fresh()->detailed_path);
}

public function test_a_project_on_an_earlier_account_keeps_its_folder_name_on_the_current_account(): void
{
    $project = $this->project(['concept_path' => 'drive-1/'.self::FOLDER.'/Concept proposal (a1b2).pdf']);
    GoogleDriveConnection::find(1)->update(['retired_at' => now()]);
    $current = GoogleDriveConnection::factory()->create(['email' => 'second@gmail.com']);

    $this->upload($project, ['detailed']);

    $this->assertStringStartsWith("drive-{$current->id}/".self::FOLDER.'/', $project->fresh()->detailed_path);
    $this->assertStringStartsWith('drive-1/', $project->fresh()->concept_path);
}

public function test_saving_again_after_storage_failed_reuses_the_folder(): void
{
    $folders = [];
    $this->partialMock(DocumentStorage::class, function (MockInterface $mock) use (&$folders) {
        $mock->shouldReceive('delete');
        $mock->shouldReceive('store')->twice()->andReturnUsing(function ($file, string $folder, string $name) use (&$folders) {
            $folders[] = $folder;

            if (count($folders) === 1) {
                throw new DocumentStorageUnavailable;
            }

            return "drive-1/{$folder}/{$name}.pdf";
        });
    });

    $form = $this->filledForm('Flood Mapping in Cauayan');
    $form->call('save')->assertHasErrors('documents');
    $form->call('save')->assertHasNoErrors();

    $this->assertSame($folders[0], $folders[1]);
}

public function test_the_browser_cannot_choose_the_folder(): void
{
    $this->assertThrows(fn () => Livewire::test('pages::submissions.create')->set('driveFolder', 'Research Drive/Other/Someone Else (aaaa)'), CannotUpdateLockedPropertyException::class);
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --filter=ProjectDriveFoldersTest`
Expected: the ten new tests FAIL, because paths still read `drive-1/submissions/<hash>.pdf` and the form has no `driveFolder`. The nine Task 1 tests PASS.

- [ ] **Step 4: Wire the form**

Add the property after `$opened`:

```php
/** The Drive folder this form saves into, kept so saving again after a failure reuses it. Locked, because a browser must never choose it. */
#[Locked]
public ?string $driveFolder = null;
```

In `save()`, inside the `try`, replace the upload loop:

```php
// Uploaded before the save starts, so a slow upload never keeps the project locked from anyone else.
// One folder for the whole save, so documents sent together stay together.
$this->driveFolder ??= $this->submission?->driveFolder() ?? Submission::newDriveFolder($this->department->code, $validated['title']);

foreach ($this->documents as $stage => $file) {
    $stored[$stage] = app(DocumentStorage::class)->store($file, $this->driveFolder, Submission::driveDocumentName($stage));
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=ProjectDriveFoldersTest`
Expected: PASS, 19 tests.

- [ ] **Step 6: Update `docs/backup-and-restore.md`**

Replace the first sentence of line 23 with:

> Project documents are stored on the Research Office's Google Drive in `Research Drive/<college code>/<project title> (<code>)/`, one folder per project. Memos are in `announcements` and profile photos in `profile-images`. Documents uploaded before these folders existed stay in `submissions`.

Add this bullet to "Keeping it working":

> - **Leave the folders as they are.** The portal finds each document by its saved path, so don't move or rename files or folders by hand. A renamed project or college keeps its old folder name on Drive, and its documents still open.

- [ ] **Step 7: Run every check**

Run: `composer ci:check`
Expected: Pint, PHPStan and all tests pass.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint tests/Feature/ProjectDriveFoldersTest.php
git add "resources/views/pages/submissions/*create.blade.php" docs/backup-and-restore.md tests/Feature/ProjectDriveFoldersTest.php
git commit -m "feat(drive): file project documents in college and project folders"
```

## Release Check

A person does this on the deployed portal after merging and before go-live, not from a local machine:

1. Sign in as a faculty member and create three test projects in a row, each with a concept proposal. This shows whether real Drive lists a new folder straight after creating it, which the tests cannot prove.
2. If a save shows "Document storage is unavailable", find `UnableToReadFile` in the log, then save the same form again. It should succeed without making a second folder. If first saves fail often, stop and rework folder creation before go-live.
3. Open each document from the portal.
4. Upload a corrected concept proposal for one project and open it again.
5. In Google Drive, as the storage account, confirm one `Research Drive/<college>/<title> (<code>)/` folder per project, with the replaced file in the trash.
6. Open a document uploaded before this change, to confirm flat files still work.
