<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\SampleCollegesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleCollegesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_twice_fills_only_the_sample_colleges_once_and_leaves_nothing_that_contacts_anyone(): void
    {
        Category::create(['name' => 'Social']);
        $college = Department::create(['code' => 'IAT', 'name' => 'Institute of Agricultural Technology']);
        Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);

        $this->seed(SampleCollegesSeeder::class);
        $this->seed(SampleCollegesSeeder::class);

        $this->assertSame(6, Submission::where('department_id', $college->id)->count());
        $this->assertSame(6, Submission::count());
        $this->assertSame(2, User::count());
        $this->assertSame(6, Submission::has('proponents')->count());
        $this->assertSame(0, Submission::where('awaiting_review', true)->count());
        $this->assertSame(0, Submission::whereNull('terminal_uploaded_at')->whereNotNull('target_date')->count());
        $this->assertSame(0, Submission::whereNotNull('concept_path')->count());
    }
}
