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

    private User $rivera;

    private User $soriano;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 9));
        $ccsict = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $cas = Department::create(['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
        $this->rivera = User::factory()->create(['name' => 'Rivera', 'department_id' => $ccsict->id]);
        $this->soriano = User::factory()->create(['name' => 'Soriano', 'department_id' => $cas->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_only_the_member_and_the_research_office_open_a_profile(): void
    {
        $this->actingAs($this->rivera)->get(route('faculty.show', $this->rivera))->assertOk();
        $this->actingAs($this->admin)->get(route('faculty.show', $this->rivera))->assertOk();
        $this->actingAs($this->soriano)->get(route('faculty.show', $this->rivera))->assertForbidden();
        $this->actingAs($this->admin)->get(route('faculty.show', $this->admin))->assertNotFound();
    }

    public function test_staff_open_any_paper_and_have_no_profile_of_their_own(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $paper = $this->publication('Rivera paper', '2025-08-01', [], $this->rivera);
        $this->actingAs($staff);

        $this->get(route('publications.show', $paper))->assertOk()->assertSee('Faculty Publications');
        $this->get(route('faculty.show', $staff))->assertNotFound();

        $page = $this->get(route('profile.edit'))->assertOk();
        foreach (User::EMAIL_KINDS as $label) {
            $page->assertDontSee($label);
        }
    }

    public function test_the_profile_lists_the_most_cited_papers_first_with_their_counts_and_totals(): void
    {
        $older = $this->publication('Older paper', '2024-03-01', [2024, 2025, 2026], $this->rivera);
        $newer = $this->publication('Newer paper', '2025-08-01', [2026], $this->rivera);
        $this->actingAs($this->rivera);

        Livewire::test('pages::faculty.show', ['user' => $this->rivera])
            ->assertSee('College of Computing Studies')
            ->assertSeeInOrder(['Older paper', '3', '2024', 'Newer paper', '1', '2025'])
            ->assertSeeHtml('data-test="publication-total">2<')
            ->assertSeeHtml('data-test="citation-total">4<')
            // Citations per year add up across both papers, from the earliest one's year.
            ->assertSeeInOrder(['2024', '1 citation', '2025', '1 citation', '2026', '2 citations']);
    }

    public function test_a_shared_paper_shows_on_each_co_authors_profile_with_the_same_count(): void
    {
        $this->publication('Shared paper', '2025-01-01', [2025, 2026], $this->rivera, $this->soriano);

        foreach ([$this->rivera, $this->soriano] as $author) {
            $this->actingAs($author);
            Livewire::test('pages::faculty.show', ['user' => $author])
                ->assertSee('Shared paper')
                ->assertSeeHtml('data-test="citation-total">2<');
        }
    }

    public function test_an_empty_profile_invites_the_member_to_add_and_tells_the_research_office_nothing_is_there(): void
    {
        $this->actingAs($this->rivera);
        Livewire::test('pages::faculty.show', ['user' => $this->rivera])
            ->assertSee('No publications yet')
            ->assertSee('Add each paper you’ve published')
            ->assertSee(route('publications.create'))
            ->assertDontSee('data-test="profile-totals"', escape: false)
            ->assertDontSee('data-test="year-chart"', escape: false);

        $this->actingAs($this->admin);
        Livewire::test('pages::faculty.show', ['user' => $this->rivera])
            ->assertSee('No publications yet')
            ->assertDontSee(route('publications.create'));
    }

    public function test_a_chart_cut_off_before_every_citation_names_the_empty_years_instead_of_saying_none(): void
    {
        $this->publication('Old paper', '2005-01-01', [2006, 2008], $this->rivera);
        $this->actingAs($this->rivera);

        Livewire::test('pages::faculty.show', ['user' => $this->rivera])
            ->assertSeeHtml('data-test="citation-total">2<')
            ->assertSee('No citations from 2012 to 2026.')
            ->assertDontSee('No citations yet.');
    }

    public function test_the_tab_title_names_whose_publications_these_are(): void
    {
        $this->actingAs($this->rivera)->get(route('faculty.show', $this->rivera))->assertSee('My Publications - ', false);
        $this->actingAs($this->admin)->get(route('faculty.show', $this->rivera))->assertSee($this->rivera->name.'’s publications - ', false);
    }

    public function test_the_research_office_lists_every_faculty_member_with_their_counts(): void
    {
        $this->publication('Shared paper', '2025-01-01', [2025, 2026], $this->rivera, $this->soriano);
        $this->publication('Solo paper', '2025-01-01', [2026], $this->rivera);
        $nobody = User::factory()->create(['name' => 'Torres']);
        $this->actingAs($this->admin);

        Livewire::test('pages::faculty.index')
            ->assertSeeInOrder(['Rivera', 'CCSICT', '2', '3', 'Soriano', 'CAS', '1', '2', 'Torres', '0', '0'])
            ->assertDontSee($this->admin->name)
            ->set('search', 'sor')
            ->assertSee('Soriano')
            ->assertDontSee('Rivera')
            ->set('search', '')
            ->set('college', 'CCSICT')
            ->assertSee('Rivera')
            ->assertDontSee('Soriano')
            ->assertDontSee($nobody->name);

        $this->actingAs($this->rivera)->get(route('faculty.index'))->assertForbidden();
    }

    public function test_the_research_office_sorts_faculty_by_name_publications_or_citations(): void
    {
        $this->publication('Shared paper', '2025-01-01', [2025], $this->rivera, $this->soriano);
        $this->publication('Solo paper', '2025-01-01', [], $this->rivera);
        User::factory()->create(['name' => 'Torres']);
        $this->actingAs($this->admin);

        Livewire::test('pages::faculty.index')
            ->assertSeeInOrder(['Rivera', 'Soriano', 'Torres'])
            ->assertSeeHtml('aria-sort="ascending"')
            // A number column opens with the most; a second click flips it.
            ->call('sort', 'publications')
            ->assertSet('sortDirection', 'desc')
            ->assertSeeInOrder(['Rivera', 'Soriano', 'Torres'])
            ->call('sort', 'publications')
            ->assertSeeInOrder(['Torres', 'Soriano', 'Rivera'])
            ->call('sort', 'name')
            ->assertSet('sortDirection', 'asc')
            ->call('sort', 'name')
            ->assertSeeInOrder(['Torres', 'Soriano', 'Rivera']);

        // Anything else typed into the address sorts by name rather than by that column.
        Livewire::withQueryParams(['sort' => 'email'])->test('pages::faculty.index')
            ->assertSeeInOrder(['Rivera', 'Soriano', 'Torres']);
    }

    public function test_a_member_without_a_college_says_so_in_words(): void
    {
        User::factory()->create(['name' => 'Torres']);
        $this->actingAs($this->admin);

        Livewire::test('pages::faculty.index')->assertSeeInOrder(['Torres', 'No college'])->assertDontSee('—');
    }

    public function test_the_profile_sorts_papers_by_year_as_well_as_by_citations(): void
    {
        $this->publication('Older paper', '2023-03-01', [2024, 2025], $this->rivera);
        $this->publication('Newer paper', '2025-08-01', [2026], $this->rivera);
        $this->publication('Middle paper', '2024-05-01', [], $this->rivera);
        $this->actingAs($this->rivera);

        Livewire::test('pages::faculty.show', ['user' => $this->rivera])
            ->assertSeeInOrder(['Older paper', 'Newer paper', 'Middle paper'])
            ->call('sort', 'year')
            ->assertSeeInOrder(['Newer paper', 'Middle paper', 'Older paper'])
            ->call('sort', 'year')
            ->assertSeeInOrder(['Older paper', 'Middle paper', 'Newer paper'])
            ->call('sort', 'cited')
            ->assertSeeInOrder(['Older paper', 'Newer paper', 'Middle paper']);
    }

    public function test_a_profile_whose_papers_nobody_has_cited_shows_the_totals_without_an_empty_chart(): void
    {
        $this->publication('Uncited paper', '2025-01-01', [], $this->rivera);
        $this->actingAs($this->rivera);

        Livewire::test('pages::faculty.show', ['user' => $this->rivera])
            ->assertSeeHtml('data-test="citation-total">0<')
            ->assertDontSeeHtml('data-test="year-chart"');
    }

    public function test_an_empty_profile_offers_one_add_button_not_two(): void
    {
        $this->actingAs($this->rivera);
        $html = Livewire::test('pages::faculty.show', ['user' => $this->rivera])->html();

        $this->assertSame(1, substr_count($html, route('publications.create')));
    }

    public function test_the_sidebar_names_the_page_for_each_role(): void
    {
        $this->actingAs($this->rivera)->get(route('dashboard'))
            ->assertSee('My Publications')
            ->assertSee(route('faculty.show', $this->rivera))
            ->assertDontSee('Faculty Publications');

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertSee('Faculty Publications')
            ->assertSee(route('faculty.index'))
            ->assertDontSee('My Publications');
    }

    /**
     * @param  list<int>  $citedIn  The year of each citing paper.
     */
    public function test_the_list_shows_20_more_at_a_time_while_the_totals_and_chart_count_every_paper(): void
    {
        $this->publication('Top paper', '2024-01-01', [2024, 2025], $this->rivera);
        foreach (range(1, 25) as $n) {
            $this->publication("Uncited paper $n", '2025-01-01', [], $this->rivera);
        }
        $this->actingAs($this->rivera);

        Livewire::test('pages::faculty.show', ['user' => $this->rivera])
            ->assertSee('Top paper')
            ->assertDontSee('Uncited paper 9')
            ->assertSeeHtml('data-test="publication-total">26<')
            ->assertSeeHtml('data-test="citation-total">2<')
            ->assertSeeInOrder(['2024', '1 citation', '2025', '1 citation'])
            ->assertSee('Showing 20 of 26')
            ->call('showMore')
            ->assertSee('Uncited paper 9')
            ->assertSee('Top paper')
            ->assertDontSeeHtml('data-test="show-more-publications"')
            ->assertSeeHtml('data-test="publication-total">26<');
    }

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
