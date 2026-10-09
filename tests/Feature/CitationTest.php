<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Citation;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The publication page: total citations, citations per year, and the citing papers its authors add and remove.
 */
class CitationTest extends TestCase
{
    use RefreshDatabase;

    private User $rivera;

    private Publication $paper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 9));
        $this->rivera = User::factory()->create(['name' => 'Rivera']);
        $this->paper = Publication::factory()->create(['title' => 'SMART-ResearchTrack', 'published_on' => '2024-02-01']);
        $this->paper->faculty()->attach($this->rivera);
    }

    public function test_the_page_shows_cited_by_and_a_bar_for_every_year_since_publication(): void
    {
        Citation::factory()->for($this->paper)->create(['year' => 2024]);
        Citation::factory()->for($this->paper)->count(2)->create(['year' => 2026]);
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSeeHtml('data-test="cited-by">3<')
            ->assertSee('data-test="year-chart"', escape: false)
            ->assertSeeInOrder(['2024', '1 citation', '2025', 'No citations', '2026', '2 citations']);

        $this->actingAs(User::factory()->create())->get(route('publications.show', $this->paper))->assertForbidden();
    }

    public function test_the_chart_axis_halves_to_a_whole_number(): void
    {
        // A peak of 3 used to round the axis to 5, with a middle tick of 2.5.
        Citation::factory()->for($this->paper)->count(3)->create(['year' => 2026]);
        $this->actingAs($this->rivera);

        // The profile chart keeps its scale; the publication page's compact chart has only a baseline.
        $this->get(route('faculty.show', $this->rivera))
            ->assertSee('-my-2">4<', false)
            ->assertSee('-my-2">2<', false)
            ->assertDontSee('-my-2">2.5<', false);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSeeHtml('data-test="year-chart"')
            ->assertDontSeeHtml('-my-2">');
    }

    public function test_the_compact_chart_drops_its_heading_and_labels_each_bar(): void
    {
        Citation::factory()->for($this->paper)->create(['year' => 2026]);
        $this->actingAs($this->rivera);

        // One citation stays short against the axis floor of 5, and its count sits over the bar.
        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertDontSeeHtml('>Citations per year<')
            ->assertSeeHtml('style="height: 20%"')
            ->assertSeeHtml('data-test="bar-count">1<');

        // The profile chart keeps its heading and its scale, with no per-bar counts.
        $this->get(route('faculty.show', $this->rivera))
            ->assertSee('Citations per year')
            ->assertDontSee('data-test="bar-count"', false);
    }

    public function test_a_paper_without_citing_papers_says_so_once(): void
    {
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSeeHtml('data-test="cited-by">0<')
            ->assertDontSee('data-test="year-chart"', escape: false)
            ->assertSee('data-test="add-citation-form"', escape: false);

        // The Research Office has nothing to add, so the empty citing-papers section is left out.
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSeeHtml('data-test="cited-by">0<')
            ->assertDontSee('Citing papers');
    }

    public function test_citing_papers_show_20_at_a_time_while_the_count_covers_them_all(): void
    {
        Citation::factory()->for($this->paper)->count(25)->create(['year' => 2025]);
        Citation::factory()->for($this->paper)->create(['year' => 2024, 'link' => 'https://example.com/oldest']);
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSeeHtml('data-test="cited-by">26<')
            ->assertSee('Showing 20 of 26')
            ->assertDontSee('https://example.com/oldest')
            ->call('showMore')
            ->assertSee('https://example.com/oldest')
            ->assertDontSeeHtml('data-test="show-more-citations"');
    }

    public function test_the_details_read_as_labelled_rows_and_skip_empty_fields(): void
    {
        $this->paper->update(['authors' => 'Rivera; Garcia, P.', 'journal' => 'Isabela Journal', 'volume' => '12', 'issue' => null, 'pages' => '45-60']);
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSeeInOrder(['Authors', 'Rivera; Garcia, P.', 'Publication date', 'Feb 1, 2024', 'Journal', 'Isabela Journal', 'Volume', '12', 'Pages', '45-60'])
            ->assertDontSee('Issue');
    }

    public function test_the_tab_title_names_the_paper(): void
    {
        $this->actingAs($this->rivera)->get(route('publications.show', $this->paper))->assertSee('SMART-ResearchTrack - ', false);
    }

    public function test_the_research_office_breadcrumb_leads_back_to_the_co_author_it_came_from(): void
    {
        $soriano = User::factory()->create(['name' => 'Soriano']);
        $this->paper->faculty()->attach($soriano);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('faculty.show', $soriano))->assertSee(route('publications.show', [$this->paper, 'author' => $soriano->id]), false);

        Livewire::withQueryParams(['author' => $soriano->id])
            ->test('pages::publications.show', ['publication' => $this->paper])
            ->assertSee(route('faculty.show', $soriano))
            ->assertDontSee(route('faculty.show', $this->rivera));

        // Someone who didn't write the paper can't be named in the breadcrumb.
        $stranger = User::factory()->create();
        Livewire::withQueryParams(['author' => $stranger->id])
            ->test('pages::publications.show', ['publication' => $this->paper])
            ->assertDontSee(route('faculty.show', $stranger));
    }

    public function test_an_author_adds_a_citing_paper(): void
    {
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->set('link', 'doi:10.2000/cites')
            ->set('year', 2025)
            ->call('addCitation')
            ->assertHasNoErrors()
            ->assertSet('link', '')
            ->assertSeeHtml('data-test="cited-by">1<');

        $citation = $this->paper->citations()->sole();
        $this->assertSame('https://doi.org/10.2000/cites', $citation->link);
        $this->assertSame(2025, $citation->year);
        $this->assertSame('added a citing paper', ActivityLog::latest('id')->first()->summary());
    }

    public function test_the_year_must_fall_between_publication_and_now(): void
    {
        $this->actingAs($this->rivera);

        foreach ([2023, 2027] as $year) {
            Livewire::test('pages::publications.show', ['publication' => $this->paper])
                ->set('link', 'https://journal.example/'.$year)
                ->set('year', $year)
                ->call('addCitation')
                ->assertHasErrors('year');
        }

        $this->assertSame(0, Citation::count());
    }

    public function test_the_same_citing_paper_is_listed_once_per_publication(): void
    {
        Citation::factory()->for($this->paper)->create(['link' => 'https://doi.org/10.2000/cites']);
        $other = Publication::factory()->create(['published_on' => '2024-01-01']);
        $other->faculty()->attach($this->rivera);
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->set('link', '10.2000/cites')
            ->call('addCitation')
            ->assertHasErrors(['link' => 'unique']);

        // The same paper can cite another publication.
        Livewire::test('pages::publications.show', ['publication' => $other])
            ->set('link', '10.2000/cites')
            ->call('addCitation')
            ->assertHasNoErrors();
    }

    public function test_an_author_removes_a_citing_paper_but_only_from_this_publication(): void
    {
        $mine = Citation::factory()->for($this->paper)->create();
        $elsewhere = Citation::factory()->create();
        $this->actingAs($this->rivera);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->call('removeCitation', $elsewhere->id)
            ->assertNotFound();
        $this->assertModelExists($elsewhere);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->call('removeCitation', $mine->id)
            ->assertSeeHtml('data-test="cited-by">0<');
        $this->assertModelMissing($mine);
        $this->assertSame('removed a citing paper', ActivityLog::latest('id')->first()->summary());
    }

    public function test_the_research_office_reads_the_page_without_changing_it(): void
    {
        $citation = Citation::factory()->for($this->paper)->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSeeHtml('data-test="cited-by">1<')
            ->assertSee($citation->link)
            ->assertDontSee('data-test="add-citation-form"', escape: false)
            ->assertDontSee('Remove citing paper')
            ->set('link', 'https://journal.example/x')
            ->call('addCitation')
            ->assertForbidden();

        Livewire::test('pages::publications.show', ['publication' => $this->paper])->call('removeCitation', $citation->id)->assertForbidden();
        $this->assertModelExists($citation);
    }
}
