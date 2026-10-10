<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Citation;
use App\Models\Department;
use App\Models\Publication;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Publications from the faculty side: the model's link and chart helpers, who may do what, and the
 * add and edit form with its shared co-authors, linked project and delete.
 */
class PublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $rivera;

    private User $soriano;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 9));
        $this->rivera = User::factory()->create(['name' => 'Rivera']);
        $this->soriano = User::factory()->create(['name' => 'Soriano']);
    }

    public function test_a_doi_typed_any_way_is_stored_in_one_form(): void
    {
        foreach (['doi:10.1000/xyz', ' 10.1000/xyz ', 'http://dx.doi.org/10.1000/xyz', 'https://doi.org/10.1000/xyz'] as $typed) {
            $this->assertSame('https://doi.org/10.1000/xyz', Publication::normalizeLink($typed));
        }

        $this->assertSame('https://journal.example/paper/12', Publication::normalizeLink('https://journal.example/paper/12'));
    }

    public function test_deleting_a_publication_takes_its_citing_papers_and_deleting_its_project_keeps_it(): void
    {
        $project = $this->project($this->rivera);
        $paper = $this->publication([$this->rivera], ['submission_id' => $project->id]);
        Citation::factory()->count(2)->for($paper)->create();

        $project->proponents()->delete();
        $project->delete();
        $this->assertNull($paper->fresh()->submission_id);

        $paper->delete();
        $this->assertSame(0, Citation::count());
        $this->assertDatabaseCount('publication_user', 0);
    }

    public function test_citations_per_year_fill_empty_years_and_stop_at_fifteen_years(): void
    {
        $paper = $this->publication([$this->rivera], ['published_on' => '2023-03-01']);
        Citation::factory()->for($paper)->create(['year' => 2024]);
        Citation::factory()->for($paper)->count(2)->create(['year' => 2026]);

        $this->assertSame([2023 => 0, 2024 => 1, 2025 => 0, 2026 => 2], Citation::perYear($paper->citations(), 2023));

        $years = Citation::perYear($paper->citations(), 1995);
        $this->assertSame(2012, array_key_first($years));
        $this->assertCount(15, $years);
    }

    public function test_authors_manage_a_paper_the_research_office_only_reads_it_and_others_cannot_see_it(): void
    {
        $paper = $this->publication([$this->rivera]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertTrue($this->rivera->can('view', $paper));
        $this->assertTrue($this->rivera->can('update', $paper));
        $this->assertTrue($this->rivera->can('delete', $paper));

        $this->assertFalse($this->soriano->can('view', $paper));
        $this->assertFalse($this->soriano->can('update', $paper));

        $this->assertTrue($admin->can('view', $paper));
        $this->assertFalse($admin->can('create', Publication::class));
        $this->assertFalse($admin->can('update', $paper));
        $this->assertFalse($admin->can('delete', $paper));

        $this->assertTrue($this->rivera->can('viewPublications', $this->rivera));
        $this->assertTrue($admin->can('viewPublications', $this->rivera));
        $this->assertFalse($this->soriano->can('viewPublications', $this->rivera));
    }

    public function test_the_list_prints_each_author_as_initials_and_last_name(): void
    {
        $paper = new Publication(['authors' => 'Juan Dela T. Cruz; Mark Anthony Rivera; Soriano; Mark Anthony Rivera Jr.; Mario Soriano III']);

        $this->assertSame('JDT Cruz, MA Rivera, Soriano, MA Rivera Jr., M Soriano III', $paper->shortAuthors());
    }

    public function test_surname_first_names_print_as_initials_and_their_whole_surname(): void
    {
        $paper = new Publication(['authors' => 'Rocel, j. a.; Haley, g.; Bogan, u; Dela Cruz, J.; Rivera, Jr.']);

        $this->assertSame('JA Rocel, G Haley, U Bogan, J Dela Cruz, Rivera, Jr.', $paper->shortAuthors());
        $this->assertSame('JA ROCEL', (new Publication(['authors' => 'JOHN ANSLEY ROCEL']))->shortAuthors());
    }

    public function test_faculty_add_a_publication_shared_with_a_co_author(): void
    {
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.create')
            ->set($this->fields(['link' => 'doi:10.1000/smart']))
            ->call('addAuthor', true)
            ->set('authorRows.1.name', 'Garcia, P.')
            ->call('addAuthor')
            ->set('authorRows.2.user_id', $this->soriano->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('publications.show', Publication::first()));

        $paper = Publication::first();
        $this->assertSame('https://doi.org/10.1000/smart', $paper->link);
        $this->assertSame('Rivera; Garcia, P.; Soriano', $paper->authors);
        $this->assertEqualsCanonicalizing([$this->rivera->id, $this->soriano->id], $paper->faculty()->pluck('users.id')->all());
        $this->assertSame('publication.created', ActivityLog::latest('id')->first()->action);
        $this->assertSame('SMART-ResearchTrack', ActivityLog::latest('id')->first()->subject_label);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('publications.create'))->assertForbidden();
    }

    public function test_the_authors_must_include_you_and_only_verified_faculty_once_each(): void
    {
        $this->actingAs($this->rivera);
        $form = fn () => Livewire::test('pages::publications.create')->set($this->fields());

        $form()->set('authorRows', [['user_id' => $this->soriano->id], ['name' => 'Garcia, P.']])->call('save')->assertHasErrors('authorRows');
        $form()->set('authorRows', [['user_id' => $this->rivera->id], ['user_id' => User::factory()->unverified()->create()->id]])->call('save')->assertHasErrors('authorRows.1.user_id');
        $form()->set('authorRows', [['user_id' => $this->rivera->id], ['user_id' => User::factory()->create(['role' => 'admin'])->id]])->call('save')->assertHasErrors('authorRows.1.user_id');
        $form()->set('authorRows', [['user_id' => $this->rivera->id], ['user_id' => $this->rivera->id]])->call('save')->assertHasErrors('authorRows.0.user_id');
        $form()->set('authorRows', [['user_id' => $this->rivera->id], ['name' => '']])->call('save')->assertHasErrors('authorRows.1.name');
        $form()->set('authorRows', [['user_id' => $this->rivera->id], ['name' => 'Garcia, P.; Cruz, R.']])->call('save')->assertHasErrors('authorRows.1.name');

        $this->assertSame(0, Publication::count());
    }

    public function test_a_paper_already_in_the_portal_is_refused_however_its_doi_is_typed(): void
    {
        $this->publication([$this->soriano], ['link' => 'https://doi.org/10.1000/smart']);
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.create')
            ->set($this->fields(['link' => 'http://dx.doi.org/10.1000/smart']))
            ->call('save')
            ->assertHasErrors(['link' => 'unique'])
            ->assertSee('Ask its authors to add you as a co-author.');
    }

    public function test_only_your_completed_projects_can_be_linked_and_a_co_author_keeps_the_link(): void
    {
        $completed = $this->project($this->rivera, ['status' => 'Completed']);
        $ongoing = $this->project($this->rivera, ['status' => 'Detailed']);
        $someoneElses = $this->project($this->soriano, ['status' => 'Completed']);
        $this->actingAs($this->rivera);

        foreach ([$ongoing, $someoneElses] as $project) {
            Livewire::test('pages::publications.create')->set($this->fields(['submission_id' => $project->id]))->call('save')->assertHasErrors('submission_id');
        }

        Livewire::test('pages::publications.create')
            ->set($this->fields(['submission_id' => $completed->id]))
            ->set('authorRows', [['user_id' => $this->rivera->id], ['user_id' => $this->soriano->id]])
            ->call('save')
            ->assertHasNoErrors();

        // Soriano isn't on the project but co-wrote the paper, so his edit keeps the link.
        $paper = Publication::first();
        $this->actingAs($this->soriano);
        Livewire::test('pages::publications.create', ['publication' => $paper])->set('journal', 'Renamed Journal')->call('save')->assertHasNoErrors();
        $this->assertSame($completed->id, $paper->fresh()->submission_id);
        $this->assertSame('Renamed Journal', $paper->fresh()->journal);
    }

    public function test_editing_rebuilds_the_author_rows_in_the_printed_order(): void
    {
        $paper = $this->publication([$this->rivera, $this->soriano], ['authors' => 'Garcia, P.; Soriano; Rivera']);
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.create', ['publication' => $paper])
            ->assertSet('authorRows', [['name' => 'Garcia, P.'], ['user_id' => $this->soriano->id], ['user_id' => $this->rivera->id]])
            ->call('removeAuthor', 0)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Soriano; Rivera', $paper->fresh()->authors);
    }

    public function test_your_own_author_row_stays_but_moves_to_its_printed_place(): void
    {
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.create')
            ->assertSeeHtml('data-test="author-you"')
            ->call('addAuthor', true)
            ->set('authorRows.1.name', 'Garcia, P.')
            ->call('moveAuthor', 1, 0)
            ->call('moveAuthor', 1, 2)
            ->assertSet('authorRows', [['name' => 'Garcia, P.'], ['user_id' => $this->rivera->id]])
            ->set($this->fields(['link' => 'doi:10.1000/second-author']))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Garcia, P.; Rivera', Publication::first()->authors);
    }

    public function test_indexed_in_takes_only_listed_databases_and_shows_on_the_paper(): void
    {
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.create')->set($this->fields(['indexed_in' => ['scopus', 'made-up']]))->call('save')->assertHasErrors('indexed_in.1');

        // Ticked out of order, stored in the list's order.
        Livewire::test('pages::publications.create')->set($this->fields(['indexed_in' => ['aci', 'scopus']]))->call('save')->assertHasNoErrors();
        $paper = Publication::sole();
        $this->assertSame(['scopus', 'aci'], $paper->indexed_in);
        $this->get(route('publications.show', $paper))->assertSeeInOrder(['Indexed in', 'Scopus, ASEAN Citation Index (ACI)']);

        // Unticking every box clears it.
        Livewire::test('pages::publications.create', ['publication' => $paper])->assertSet('indexed_in', ['scopus', 'aci'])->set('indexed_in', [])->call('save')->assertHasNoErrors();
        $this->assertNull($paper->fresh()->indexed_in);
        $this->get(route('publications.show', $paper))->assertDontSee('Indexed in');
    }

    public function test_only_authors_open_the_edit_form(): void
    {
        $paper = $this->publication([$this->rivera]);

        $this->actingAs($this->rivera)->get(route('publications.edit', $paper))->assertOk();
        $this->actingAs($this->soriano)->get(route('publications.edit', $paper))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('publications.edit', $paper))->assertForbidden();
    }

    public function test_the_date_cannot_move_past_the_year_of_a_citing_paper(): void
    {
        $paper = $this->publication([$this->rivera], ['published_on' => '2024-05-01']);
        Citation::factory()->for($paper)->create(['year' => 2024]);
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.create', ['publication' => $paper])
            ->set('published_on', '2025-01-15')
            ->call('save')
            ->assertHasErrors('published_on')
            ->assertSee('A citing paper is from 2024.');

        $this->assertSame('2024-05-01', $paper->fresh()->published_on->toDateString());
    }

    public function test_deleting_removes_the_paper_and_its_citing_papers_and_logs_it(): void
    {
        $paper = $this->publication([$this->rivera]);
        Citation::factory()->count(3)->for($paper)->create();
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.create', ['publication' => $paper])
            ->assertSee('Its 3 citing papers are deleted with it')
            ->call('delete')
            ->assertRedirect(route('faculty.show', $this->rivera));

        $this->assertSame(0, Publication::count());
        $this->assertSame(0, Citation::count());
        $log = ActivityLog::latest('id')->first();
        $this->assertSame('publication.deleted', $log->action);
        $this->assertSame(3, $log->properties['citations']);
        $this->assertSame('deleted a publication', $log->summary());
    }

    public function test_an_author_with_publications_cannot_be_deleted(): void
    {
        $this->publication([$this->rivera]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test('pages::users.index')
            ->call('confirmDelete', $this->rivera->id)
            ->assertSee('one publication')
            ->call('delete');

        $this->assertModelExists($this->rivera);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return [
            'title' => 'SMART-ResearchTrack',
            'journal' => 'ISU Research Journal',
            'volume' => '4',
            'published_on' => '2025-06-01',
            'link' => 'https://doi.org/10.1000/smart',
            ...$overrides,
        ];
    }

    /**
     * @param  list<User>  $authors
     * @param  array<string, mixed>  $attributes
     */
    private function publication(array $authors, array $attributes = []): Publication
    {
        $publication = Publication::factory()->create($attributes);
        $publication->faculty()->attach(collect($authors)->pluck('id'));

        return $publication;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function project(User $by, array $attributes = []): Submission
    {
        $project = $by->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => Department::firstOrCreate(['code' => 'CCSICT'], ['name' => 'College of Computing Studies'])->id,
            'title' => 'Project of '.$by->name,
            'concept_path' => 'submissions/concept.pdf',
            'status' => 'Concept',
            ...$attributes,
        ]);
        $project->proponents()->create(['user_id' => $by->id, 'study' => 1, 'role' => 'Leader']);

        return $project;
    }
}
