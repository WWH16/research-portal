<?php

namespace Tests\Feature;

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Models\User;
use App\Services\DocumentStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class ProjectDriveFoldersTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '\([a-z0-9]{4}\)';

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

    /**
     * @param  list<string>  $stages
     */
    private function upload(Submission $project, array $stages, ?string $title = null): void
    {
        $form = Livewire::test('pages::submissions.create', ['submission' => $project->fresh()]);

        if ($title !== null) {
            $form->set('title', $title);
        }

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
}
