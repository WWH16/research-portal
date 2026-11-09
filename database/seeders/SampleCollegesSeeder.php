<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sample faculty and projects for the colleges that had none, so the dashboard has every college to show. Run by hand
 * with --class; DatabaseSeeder never calls it, so plain db:seed stays accounts only.
 *
 * Nothing here can reach anyone or Google Drive. No project waits for review, open projects have no target date for
 * projects:remind to act on, and no document path is set. The accounts get random passwords and cannot be signed in to.
 */
class SampleCollegesSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const PROJECTS = [
        'IAT' => [
            'Organic Fertilizer Trials on Lowland Rice Varieties',
            'Post-Harvest Losses in Yellow Corn Production',
            'Integrated Pest Management Adoption Among Vegetable Growers',
            'Water Use Efficiency in Small-Scale Irrigation Systems',
            'Mechanized Transplanting Costs for Smallholder Rice Farmers',
            'Soil Fertility Mapping of Upland Farms',
        ],
        'CCJE' => [
            'Community Policing Practices in Rural Barangays',
            'Crime Incident Reporting Behavior Among College Students',
            'Forensic Laboratory Readiness of Criminology Programs',
            'Effectiveness of Barangay Tanod Training Programs',
            'Road Traffic Incident Patterns Along National Highways',
        ],
        'CED' => [
            'Blended Learning Readiness of Pre-Service Teachers',
            'Reading Comprehension Interventions for Grade Four Learners',
            'Mother Tongue Instruction in Multilingual Classrooms',
            'Classroom Assessment Practices of Beginning Teachers',
        ],
        'PS' => [
            'Work Immersion Outcomes of Senior High School Students',
            'Laboratory Skills Retention in Technical Drafting Courses',
            'Career Choice Factors of Polytechnic School Graduates',
        ],
        'COL' => [
            'Awareness of Data Privacy Rights Among Barangay Officials',
            'Access to Free Legal Aid in Rural Municipalities',
        ],
    ];

    /**
     * Create what does not exist yet, so running it again changes nothing.
     */
    public function run(): void
    {
        $categories = Category::orderBy('id')->pluck('id');

        foreach (Department::whereIn('code', array_keys(self::PROJECTS))->get() as $college) {
            $faculty = collect([1, 2])->map(fn (int $number): User => User::unguarded(fn () => User::firstOrCreate(
                ['email' => 'sample.'.Str::lower($college->code).$number.'@isu.edu.ph'],
                ['name' => "{$college->code} Sample Faculty {$number}", 'password' => Str::password(), 'role' => 'faculty', 'department_id' => $college->id, 'email_verified_at' => now(), 'approved_at' => now()],
            )));

            foreach (self::PROJECTS[$college->code] as $index => $title) {
                $status = Submission::STATUSES[$index % count(Submission::STATUSES)];
                $owner = $faculty[$index % 2];
                // The first of a month already reached this year, spread so the monthly chart has more than one bar
                $filed = now()->startOfYear()->addMonths(($college->id + $index * 2) % now()->month);

                $project = Submission::unguarded(fn (): Submission => Submission::firstOrCreate(
                    ['department_id' => $college->id, 'title' => $title],
                    [
                        'user_id' => $owner->id,
                        'category_id' => $categories[$index % $categories->count()],
                        'abstract' => __('Sample project for the :college dashboard.', ['college' => $college->code]),
                        'status' => $status,
                        'awaiting_review' => false,
                        'concept_passed' => true,
                        'target_date' => $status === 'Completed' ? now()->addMonth() : null,
                        'terminal_uploaded_at' => $status === 'Completed' ? now() : null,
                        'created_at' => $filed,
                    ],
                ));

                if ($project->wasRecentlyCreated) {
                    $project->proponents()->create(['user_id' => $owner->id, 'study' => 1, 'role' => 'Leader']);
                }
            }
        }
    }
}
