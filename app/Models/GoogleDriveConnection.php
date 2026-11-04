<?php

namespace App\Models;

use Database\Factories\GoogleDriveConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A Google account whose Drive stores submission documents. The current one, without retired_at, takes new
 * uploads; an admin saves its address first and connects it once Google hands over a refresh token. Earlier
 * accounts are retired, not deleted, so the documents stored on them keep opening. The token is encrypted at
 * rest and never leaves the model in its array or JSON form.
 *
 * @property int $id
 * @property string $email
 * @property string|null $refresh_token
 * @property string|null $folder_id
 * @property int|null $connected_by
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['email', 'refresh_token', 'folder_id', 'connected_by', 'retired_at'])]
#[Hidden(['refresh_token'])]
class GoogleDriveConnection extends Model
{
    /** @use HasFactory<GoogleDriveConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['refresh_token' => 'encrypted', 'retired_at' => 'datetime'];
    }

    /**
     * Where the cache keeps when Google last refused an upload because the account is full. A school account's
     * own limit is never reported, so a refused upload is the only sign; losing the entry only means the warning
     * waits for the next refusal.
     */
    public function fullKey(): string
    {
        return "google-drive.full.{$this->id}";
    }

    /**
     * The account new documents go to: the one an admin hasn't replaced.
     */
    public static function current(): ?self
    {
        return static::whereNull('retired_at')->first();
    }

    /**
     * How many files each account holds, by kind, each count keyed by account id. Every upload lands on the storage
     * account, so a kind missing here is a file the Google Drive page can't see.
     *
     * @return array{documents: Collection<int, int>, memos: Collection<int, int>, photos: Collection<int, int>}
     */
    public static function filesPerAccount(): array
    {
        $documentColumns = array_map(fn (string $stage) => $stage.'_path', array_keys(Submission::DOCUMENTS));

        return [
            'documents' => self::countByAccount(Submission::query()->toBase()->get($documentColumns)->flatMap(fn (object $row) => array_values((array) $row))),
            'memos' => self::countByAccount(Announcement::query()->toBase()->pluck('attachment_path')),
            'photos' => self::countByAccount(User::query()->toBase()->pluck('profile_image')),
        ];
    }

    /**
     * Whether any file still depends on this account, so removing it would stop that file opening.
     */
    public function holdsFiles(): bool
    {
        return collect(self::filesPerAccount())->contains(fn (Collection $kind) => $kind->has($this->id));
    }

    /**
     * Count saved paths by the account each one names, as "drive-{account id}/...". A path naming no account counts for none.
     *
     * @param  Collection<array-key, string|null>  $paths
     * @return Collection<int, int>
     */
    private static function countByAccount(Collection $paths): Collection
    {
        return $paths
            ->filter(fn (?string $path) => $path !== null && str_starts_with($path, 'drive-'))
            ->countBy(fn (string $path) => (int) Str::between($path, 'drive-', '/'));
    }

    /**
     * Whether the portal can reach the account's Drive: the address alone isn't enough.
     */
    public function isConnected(): bool
    {
        return $this->refresh_token !== null;
    }

    /**
     * What stops uploads right now, in a sentence for the sidebar's Google Drive warning; null when all is well.
     */
    public static function issue(): ?string
    {
        $account = self::current();

        $issue = match (true) {
            $account === null => __('No Google Drive storage account is saved, so documents can’t be uploaded.'),
            ! $account->isConnected() => __('Google Drive :email is disconnected, so documents can’t be uploaded or opened.', ['email' => $account->email]),
            Cache::has($account->fullKey()) => __('Google Drive :email is full, so documents can’t be uploaded.', ['email' => $account->email]),
            default => null,
        };

        return is_string($issue) ? $issue : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
