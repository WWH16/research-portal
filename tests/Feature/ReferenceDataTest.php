<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function pages(): array
    {
        return [
            'research types' => ['research-types.index', 'Research Types'],
            'categories' => ['categories.index', 'Categories'],
            'departments' => ['departments.index', 'Departments'],
        ];
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function namedModels(): array
    {
        return [
            'research types' => [ResearchType::class, 'pages::research-types.index', 'research_types'],
            'categories' => [Category::class, 'pages::categories.index', 'categories'],
        ];
    }

    #[DataProvider('pages')]
    public function test_guests_are_redirected_to_the_login_page(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[DataProvider('pages')]
    public function test_page_is_displayed_to_admins(string $route, string $heading): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route($route))->assertOk()->assertSee($heading);
    }

    #[DataProvider('pages')]
    public function test_faculty_are_forbidden(string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => 'faculty']));

        $this->get(route($route))->assertForbidden();
    }

    #[DataProvider('namedModels')]
    public function test_named_record_can_be_created_and_renamed(string $model, string $component): void
    {
        $this->actingAs(User::factory()->create());

        $page = Livewire::test($component)
            ->call('create')
            ->set('name', '  Thesis  ')
            ->call('save')
            ->assertHasNoErrors();

        $record = $model::sole();
        $this->assertSame('Thesis', $record->name);

        $page->call('edit', $record->id)
            ->assertSet('name', 'Thesis')
            ->set('name', 'Capstone')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Capstone');

        $this->assertSame('Capstone', $record->fresh()->name);
        $this->assertSame(1, $model::count());
    }

    #[DataProvider('namedModels')]
    public function test_named_record_name_is_required_and_unique(string $model, string $component): void
    {
        $this->actingAs(User::factory()->create());

        $existing = $model::create(['name' => 'Thesis']);

        Livewire::test($component)
            ->call('create')
            ->call('save')
            ->assertHasErrors(['name' => 'required'])
            ->set('name', 'Thesis')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);

        Livewire::test($component)
            ->call('edit', $existing->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $model::count());
    }

    #[DataProvider('namedModels')]
    public function test_unused_named_record_can_be_deleted(string $model, string $component): void
    {
        $this->actingAs(User::factory()->create());

        $record = $model::create(['name' => 'Thesis']);

        Livewire::test($component)
            ->call('confirmDelete', $record->id)
            ->call('delete');

        $this->assertModelMissing($record);
    }

    #[DataProvider('namedModels')]
    public function test_named_record_used_by_a_submission_cannot_be_deleted(string $model, string $component, string $table): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $submission = $this->createSubmission($user);
        $record = $model::findOrFail($submission->{str($table)->singular().'_id'});

        Livewire::test($component)
            ->call('confirmDelete', $record->id)
            ->assertSee('can’t be deleted')
            ->call('delete');

        $this->assertModelExists($record);
    }

    public function test_department_can_be_created_and_updated(): void
    {
        $this->actingAs(User::factory()->create());

        $page = Livewire::test('pages::departments.index')
            ->call('create')
            ->set('code', ' ccs ')
            ->set('name', 'College of Computing Studies')
            ->call('save')
            ->assertHasNoErrors();

        $department = Department::sole();
        $this->assertSame('CCS', $department->code);

        $page->call('edit', $department->id)
            ->set('name', 'College of Computer Studies')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('College of Computer Studies');

        $this->assertSame('College of Computer Studies', $department->fresh()->name);
    }

    public function test_department_code_is_validated(): void
    {
        $this->actingAs(User::factory()->create());

        Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);

        Livewire::test('pages::departments.index')
            ->call('create')
            ->call('save')
            ->assertHasErrors(['code' => 'required', 'name' => 'required'])
            ->set('code', 'ccs')
            ->set('name', 'Duplicate')
            ->call('save')
            ->assertHasErrors(['code' => 'unique'])
            ->set('code', 'C S')
            ->call('save')
            ->assertHasErrors(['code' => 'alpha_dash']);

        $this->assertSame(1, Department::count());
    }

    public function test_unused_department_can_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);

        Livewire::test('pages::departments.index')
            ->call('confirmDelete', $department->id)
            ->call('delete');

        $this->assertModelMissing($department);
    }

    public function test_department_with_members_cannot_be_deleted(): void
    {
        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);

        $this->actingAs(User::factory()->create(['department_id' => $department->id]));

        Livewire::test('pages::departments.index')
            ->call('confirmDelete', $department->id)
            ->assertSee('Still used by one member')
            ->call('delete');

        $this->assertModelExists($department);
    }

    private function createSubmission(User $user): Submission
    {
        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);

        return $user->submissions()->create([
            'research_type_id' => ResearchType::create(['name' => 'Thesis'])->id,
            'category_id' => Category::create(['name' => 'Computing'])->id,
            'department_id' => $department->id,
            'title' => 'Sample Proposal',
            'file_path' => 'submissions/sample.pdf',
        ]);
    }
}
