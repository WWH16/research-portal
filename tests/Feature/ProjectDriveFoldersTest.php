<?php

namespace Tests\Feature;

use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDriveFoldersTest extends TestCase
{
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
}
