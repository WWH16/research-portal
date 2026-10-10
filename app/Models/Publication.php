<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PublicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A paper a faculty member published, entered by hand. One record per paper: portal co-authors are
 * tagged on it rather than each entering a copy. "Cited by" counts its citations rows; nothing is stored.
 *
 * @property int $id
 * @property int|null $submission_id
 * @property string $title
 * @property string $authors
 * @property string $journal
 * @property string|null $volume
 * @property string|null $issue
 * @property string|null $pages
 * @property CarbonInterface $published_on
 * @property string|null $description
 * @property string $link
 * @property list<string>|null $indexed_in Keys of INDEXES.
 */
#[Fillable([
    'submission_id',
    'title',
    'authors',
    'journal',
    'volume',
    'issue',
    'pages',
    'published_on',
    'description',
    'link',
    'indexed_in',
])]
class Publication extends Model
{
    /** @use HasFactory<PublicationFactory> */
    use HasFactory;

    /** The databases a paper can be listed in, keyed by what is stored, in the order they are shown. */
    public const INDEXES = [
        'scopus' => 'Scopus',
        'web_of_science' => 'Web of Science',
        'aci' => 'ASEAN Citation Index (ACI)',
        'google_scholar' => 'Google Scholar',
        'other' => 'Other',
    ];

    protected function casts(): array
    {
        return [
            'published_on' => 'date',
            'indexed_in' => 'array',
        ];
    }

    /**
     * The portal faculty who wrote it.
     *
     * @return BelongsToMany<User, $this>
     */
    public function faculty(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The papers that cite it.
     *
     * @return HasMany<Citation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class);
    }

    /**
     * The completed project it came out of, if any.
     *
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * The authors as the publication list prints them: initials, then the last name, so "Juan Dela T. Cruz" reads "JDT Cruz".
     * A name typed surname first ("Cruz, J. D.") gives the same "JD Cruz".
     */
    public function shortAuthors(): string
    {
        // ponytail: the last word is taken as the surname, so a two-word surname comes out wrong; store surnames apart if that matters.
        $initials = fn (array $words) => implode('', array_map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)), $words));

        return collect(explode(';', $this->authors))->map(function (string $name) use ($initials) {
            // Surname first: everything before the comma is the surname, so "Dela Cruz, J." keeps both words.
            [$surname, $given] = array_pad(explode(',', $name, 2), 2, null);
            if ($given !== null && ! preg_match('/^\s*(jr|sr|ii|iii|iv)\.?\s*$/i', $given)) {
                return trim($initials(preg_split('/\s+/', trim($given), -1, PREG_SPLIT_NO_EMPTY) ?: []).' '.trim($surname));
            }
            $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            // A suffix such as Jr. or III follows the surname instead of being taken for it.
            $suffix = count($words) > 1 && preg_match('/^(jr|sr|ii|iii|iv)\.?$/i', end($words)) ? ' '.array_pop($words) : '';
            $last = array_pop($words);

            return trim($initials($words).' '.$last.$suffix);
        })->filter()->join(', ');
    }

    public function authoredBy(User $user): bool
    {
        return $this->faculty()->whereKey($user->id)->exists();
    }

    /**
     * One form of each link, so the same paper typed two ways is still caught as a duplicate. A DOI however
     * it is typed ("doi:10.1000/xyz", a bare "10.1000/xyz", a dx.doi.org address) becomes https://doi.org/10.1000/xyz.
     */
    public static function normalizeLink(string $input): string
    {
        $link = trim($input);

        return preg_match('~^(?:doi:\s*|https?://(?:dx\.|www\.)?doi\.org/)?(10\.\d{4,9}/\S+)$~i', $link, $match)
            ? 'https://doi.org/'.$match[1]
            : $link;
    }
}
