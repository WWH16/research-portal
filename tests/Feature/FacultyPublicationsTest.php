<?php

namespace Tests\Feature;

use App\Models\Citation;
use App\Models\Department;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A faculty member's publications profile, and the Research Office's list of every faculty member.
 */
class FacultyPublicationsTest extends TestCase
{
    use RefreshDatabase;

    private User $rocel;

    private User $siton;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 9));
        $ccsict = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $cas = Department::create(['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
        $this->rocel = User::factory()->create(['name' => 'Rocel', 'department_id' => $ccsict->id]);
        $this->siton = User::factory()->create(['name' => 'Siton', 'department_id' => $cas->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_only_the_member_and_the_research_office_open_a_profile(): void
    {
        $this->actingAs($this->rocel)->get(route('faculty.show', $this->rocel))->assertOk();
        $this->actingAs($this->admin)->get(route('faculty.show', $this->rocel))->assertOk();
        $this->actingAs($this->siton)->get(route('faculty.show', $this->rocel))->assertForbidden();
        $this->actingAs($this->admin)->get(route('faculty.show', $this->admin))->assertNotFound();
    }

    public function test_the_profile_lists_papers_newest_first_with_their_counts_and_totals(): void
    {
        $older = $this->publication('Older paper', '2024-03-01', [2024, 2025, 2026], $this->rocel);
        $newer = $this->publication('Newer paper', '2025-08-01', [2026], $this->rocel);
        $this->actingAs($this->rocel);

        Livewire::test('pages::faculty.show', ['user' => $this->rocel])
            ->assertSeeInOrder(['Newer paper', '2025', '1', 'Older paper', '2024', '3'])
            ->assertSeeHtml('data-test="publication-total">2<')
            ->assertSeeHtml('data-test="citation-total">4<')
            // Citations per year add up across both papers, from the earliest one's year.
            ->assertSee('2024: 1 citation')
            ->assertSee('2025: 1 citation')
            ->assertSee('2026: 2 citations');
    }

    public function test_a_shared_paper_shows_on_each_co_authors_profile_with_the_same_count(): void
    {
        $this->publication('Shared paper', '2025-01-01', [2025, 2026], $this->rocel, $this->siton);

        foreach ([$this->rocel, $this->siton] as $author) {
            $this->actingAs($author);
            Livewire::test('pages::faculty.show', ['user' => $author])
                ->assertSee('Shared paper')
                ->assertSeeHtml('data-test="citation-total">2<');
        }
    }

    public function test_an_empty_profile_invites_the_member_to_add_and_tells_the_research_office_nothing_is_there(): void
    {
        $this->actingAs($this->rocel);
        Livewire::test('pages::faculty.show', ['user' => $this->rocel])
            ->assertSee('No publications yet')
            ->assertSee('Add each paper you’ve published')
            ->assertSee(route('publications.create'))
            ->assertDontSee('data-test="year-chart"', escape: false);

        $this->actingAs($this->admin);
        Livewire::test('pages::faculty.show', ['user' => $this->rocel])
            ->assertSee('No publications yet')
            ->assertDontSee(route('publications.create'));
    }

    public function test_the_research_office_lists_every_faculty_member_with_their_counts(): void
    {
        $this->publication('Shared paper', '2025-01-01', [2025, 2026], $this->rocel, $this->siton);
        $this->publication('Solo paper', '2025-01-01', [2026], $this->rocel);
        $nobody = User::factory()->create(['name' => 'Tabago']);
        $this->actingAs($this->admin);

        Livewire::test('pages::faculty.index')
            ->assertSeeInOrder(['Rocel', 'CCSICT', '2', '3', 'Siton', 'CAS', '1', '2', 'Tabago', '0', '0'])
            ->assertDontSee($this->admin->name)
            ->set('search', 'sit')
            ->assertSee('Siton')
            ->assertDontSee('Rocel')
            ->set('search', '')
            ->set('college', 'CCSICT')
            ->assertSee('Rocel')
            ->assertDontSee('Siton')
            ->assertDontSee($nobody->name);

        $this->actingAs($this->rocel)->get(route('faculty.index'))->assertForbidden();
    }

    public function test_the_sidebar_names_the_page_for_each_role(): void
    {
        $this->actingAs($this->rocel)->get(route('dashboard'))
            ->assertSee('My Publications')
            ->assertSee(route('faculty.show', $this->rocel))
            ->assertDontSee('Faculty Publications');

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertSee('Faculty Publications')
            ->assertSee(route('faculty.index'))
            ->assertDontSee('My Publications');
    }

    /**
     * @param  list<int>  $citedIn  The year of each citing paper.
     */
    private function publication(string $title, string $published, array $citedIn, User ...$authors): Publication
    {
        $publication = Publication::factory()->create(['title' => $title, 'published_on' => $published]);
        $publication->faculty()->attach(collect($authors)->pluck('id'));

        foreach ($citedIn as $year) {
            Citation::factory()->for($publication)->create(['year' => $year]);
        }

        return $publication;
    }
}
