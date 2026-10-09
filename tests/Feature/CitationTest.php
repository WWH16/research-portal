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
 * The publication page: "Cited by N", citations per year, and the citing papers its authors add and remove.
 */
class CitationTest extends TestCase
{
    use RefreshDatabase;

    private User $rocel;

    private Publication $paper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 9));
        $this->rocel = User::factory()->create(['name' => 'Rocel']);
        $this->paper = Publication::factory()->create(['title' => 'SMART-ResearchTrack', 'published_on' => '2024-02-01']);
        $this->paper->faculty()->attach($this->rocel);
    }

    public function test_the_page_shows_cited_by_and_a_bar_for_every_year_since_publication(): void
    {
        Citation::factory()->for($this->paper)->create(['year' => 2024]);
        Citation::factory()->for($this->paper)->count(2)->create(['year' => 2026]);
        $this->actingAs($this->rocel);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSee('Cited by 3')
            ->assertSee('data-test="year-chart"', escape: false)
            ->assertSeeInOrder(['2024', '1 citation', '2025', 'No citations', '2026', '2 citations']);

        $this->actingAs(User::factory()->create())->get(route('publications.show', $this->paper))->assertForbidden();
    }

    public function test_the_chart_axis_halves_to_a_whole_number(): void
    {
        // A peak of 3 used to round the axis to 5, with a middle tick of 2.5.
        Citation::factory()->for($this->paper)->count(3)->create(['year' => 2026]);
        $this->actingAs($this->rocel);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSeeHtml('-my-2">4<')
            ->assertSeeHtml('-my-2">2<')
            ->assertDontSeeHtml('-my-2">2.5<');
    }

    public function test_a_paper_without_citing_papers_says_so(): void
    {
        $this->actingAs($this->rocel);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSee('Cited by 0')
            ->assertSee('No citations yet.')
            ->assertSee('No citing papers yet. Add each paper');
    }

    public function test_an_author_adds_a_citing_paper(): void
    {
        $this->actingAs($this->rocel);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->set('link', 'doi:10.2000/cites')
            ->set('year', 2025)
            ->call('addCitation')
            ->assertHasNoErrors()
            ->assertSet('link', '')
            ->assertSee('Cited by 1');

        $citation = $this->paper->citations()->sole();
        $this->assertSame('https://doi.org/10.2000/cites', $citation->link);
        $this->assertSame(2025, $citation->year);
        $this->assertSame('added a citing paper', ActivityLog::latest('id')->first()->summary());
    }

    public function test_the_year_must_fall_between_publication_and_now(): void
    {
        $this->actingAs($this->rocel);

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
        $other->faculty()->attach($this->rocel);
        $this->actingAs($this->rocel);

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
        $this->actingAs($this->rocel);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->call('removeCitation', $elsewhere->id)
            ->assertNotFound();
        $this->assertModelExists($elsewhere);

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->call('removeCitation', $mine->id)
            ->assertSee('Cited by 0');
        $this->assertModelMissing($mine);
        $this->assertSame('removed a citing paper', ActivityLog::latest('id')->first()->summary());
    }

    public function test_the_research_office_reads_the_page_without_changing_it(): void
    {
        $citation = Citation::factory()->for($this->paper)->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test('pages::publications.show', ['publication' => $this->paper])
            ->assertSee('Cited by 1')
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
