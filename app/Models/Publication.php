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
])]
class Publication extends Model
{
    /** @use HasFactory<PublicationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'published_on' => 'date',
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
