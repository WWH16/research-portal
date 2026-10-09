<?php

namespace App\Models;

use Database\Factories\CitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A paper that cites a publication: its DOI or link and the year it came out. The Research Office
 * checks it by opening the link; there is no verification step.
 *
 * @property int $id
 * @property int $publication_id
 * @property string $link
 * @property int $year
 */
#[Fillable(['link', 'year'])]
class Citation extends Model
{
    /** @use HasFactory<CitationFactory> */
    use HasFactory;

    /** Most years a citations chart shows, so its columns stay readable. Earlier years still count in every total. */
    public const CHART_YEARS = 15;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Publication, $this>
     */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /**
     * Citing papers per year, from $firstYear (or the last CHART_YEARS years, whichever is later) to this
     * year, with empty years as 0.
     *
     * @param  Builder<self>|HasMany<self, Publication>  $citations
     * @return array<int, int>
     */
    public static function perYear(Builder|HasMany $citations, int $firstYear): array
    {
        $from = max($firstYear, now()->year - self::CHART_YEARS + 1);

        $counts = (clone $citations)->toBase()
            ->where('year', '>=', $from)
            ->selectRaw('year, count(*) as total')
            ->groupBy('year')
            ->pluck('total', 'year');

        return collect(range($from, now()->year))->mapWithKeys(fn (int $year) => [$year => (int) $counts->get($year, 0)])->all();
    }
}
