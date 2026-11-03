<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff and people outside the campus have no portal account, so they go on a project by typed name only.
 */
class ProponentWithoutAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    public function test_someone_without_an_account_is_listed_by_name(): void
    {
        $this->fakeDrive();

        $navarro = $this->facultyWithCollege();
        $users = User::count();

        $this->actingAs($navarro);

        $this->fillProject(Livewire::test('pages::submissions.create'))
            ->call('addProponent', true)
            ->assertSet('proponents.1', ['study' => 1, 'user_id' => null, 'name' => '', 'role' => 'Staff'])
            ->assertSeeHtml('data-test="proponent-name"')
            ->set('proponents.1.name', '  Maria   L. Santos ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('submissions.index'));

        $row = Submission::sole()->proponents()->where('study', 1)->whereNull('user_id')->sole();

        $this->assertSame('Maria L. Santos', $row->name, 'The typed name is saved with its spaces tidied.');
        $this->assertSame('Staff', $row->role);
        $this->assertSame($users, User::count(), 'No account is made for them.');
        $this->assertSame([$navarro->id], Submission::sole()->people()->pluck('id')->all(), 'Notices go to account holders only.');

        Livewire::test('pages::submissions.create', ['submission' => Submission::sole()])
            ->assertSet('proponents.1.name', 'Maria L. Santos')
            ->assertSeeHtml('data-test="proponent-name"')
            ->assertSee('Not in the portal');
    }

    public function test_named_rows_are_validated(): void
    {
        $this->fakeDrive();

        $navarro = $this->facultyWithCollege();

        $this->actingAs($navarro);

        $leader = ['study' => 1, 'user_id' => $navarro->id, 'role' => 'Leader'];

        $this->fillProject(Livewire::test('pages::submissions.create'))
            ->set('proponents', [$leader, ['study' => 1, 'user_id' => null, 'name' => '   ', 'role' => 'Staff']])
            ->call('save')
            ->assertHasErrors(['proponents.1.name' => 'required'])
            ->set('proponents', [$leader, ['study' => 1, 'user_id' => null, 'name' => str_repeat('a', 151), 'role' => 'Staff']])
            ->call('save')
            ->assertHasErrors(['proponents.1.name' => 'max'])
            ->set('proponents', [
                $leader,
                ['study' => 1, 'user_id' => null, 'name' => 'Maria Santos', 'role' => 'Staff'],
                ['study' => 1, 'user_id' => null, 'name' => 'MARIA SANTOS', 'role' => 'Co-Leader'],
            ])
            ->call('save')
            ->assertHasErrors(['proponents']);

        $this->assertSame(0, Submission::count());
    }

    public function test_a_named_row_never_carries_an_account(): void
    {
        $this->fakeDrive();

        $navarro = $this->facultyWithCollege();
        $soriano = User::factory()->create();

        $this->actingAs($navarro);

        // A client that sends both is treated as a named row, so it can't slip someone's account in with a name.
        $this->fillProject(Livewire::test('pages::submissions.create'))
            ->set('proponents', [
                ['study' => 1, 'user_id' => $navarro->id, 'role' => 'Leader'],
                ['study' => 1, 'user_id' => $soriano->id, 'name' => 'Pedro Cruz', 'role' => 'Staff'],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $project = Submission::sole();

        $this->assertNull($project->proponents()->where('name', 'Pedro Cruz')->value('user_id'));
        $this->assertFalse($project->involves($soriano));
        $this->actingAs($soriano)->get(route('submissions.edit', $project))->assertForbidden();
    }

    public function test_a_named_row_is_linked_once_the_person_has_an_account(): void
    {
        $navarro = $this->facultyWithCollege();
        $project = $this->projectWithNamedStaff($navarro);
        $soriano = User::factory()->create(['name' => 'Ana Soriano']);

        $this->actingAs($navarro);

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('proponents', [
                ['study' => 1, 'user_id' => $navarro->id, 'name' => null, 'role' => 'Leader'],
                ['study' => 1, 'user_id' => $soriano->id, 'name' => null, 'role' => 'Staff'],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, $project->proponents()->whereNull('user_id')->count());
        $this->assertTrue($project->involves($soriano));
        $this->assertSame(
            ['added' => [['Ana Soriano', 'Staff']], 'removed' => [['Ana Soriano', 'Staff']]],
            $project->history()->first()->properties['proponents'],
            'The log names both the typed row and the account.',
        );
    }

    public function test_lists_the_review_panel_and_the_export_show_named_proponents(): void
    {
        $this->fakeDrive();

        $navarro = $this->facultyWithCollege();
        $project = $this->projectWithNamedStaff($navarro);

        $this->actingAs($navarro);

        Livewire::test('pages::submissions.index')->assertSee('A Soriano');
        $this->get(route('drive.show', $project))->assertOk()->assertSee('Ana Soriano')->assertSee('Not in the portal');

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test('pages::reviews.index')
            ->assertSee('A Soriano')
            ->call('review', $project->id)
            ->assertSee('Ana Soriano (Staff, not in the portal)');

        Livewire::test('pages::submissions.index')->set('search', 'Soriano')->assertSee('Named Staff Project');

        $component = Livewire::test('pages::submissions.index')->call('export');
        $csv = base64_decode(data_get($component->effects, 'download.content'));

        $this->assertStringContainsString('"Study 1: '.$navarro->name.' (Leader), Ana Soriano (Staff)",2', $csv, 'A named proponent counts as one more person.');
    }

    private function facultyWithCollege(): User
    {
        $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computer Studies']);

        return User::factory()->create(['department_id' => $department->id]);
    }

    private function projectWithNamedStaff(User $leader): Submission
    {
        $project = $leader->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $leader->department_id,
            'title' => 'Named Staff Project',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            'concept_path' => 'drive-1/submissions/sample.pdf',
            'awaiting_review' => true,
        ]);

        $project->proponents()->createMany([
            ['user_id' => $leader->id, 'study' => 1, 'role' => 'Leader'],
            ['name' => 'Ana Soriano', 'study' => 1, 'role' => 'Staff'],
        ]);

        return $project;
    }

    private function fillProject($component)
    {
        return $component
            ->set('title', 'Sample Proposal '.Str::random(4))
            ->set('abstract', 'An abstract.')
            ->set('category_id', Category::firstOrCreate(['name' => 'Computing'])->id)
            ->set('start_date', now()->toDateString())
            ->set('target_date', now()->addYear()->toDateString())
            ->set('documents.concept', UploadedFile::fake()->create('proposal.pdf', 500, 'application/pdf'));
    }
}
