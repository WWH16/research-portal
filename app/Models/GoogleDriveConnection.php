<?php

namespace App\Models;

use Database\Factories\GoogleDriveConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The one Google account whose Drive stores submission documents. An admin saves its address first; it
 * is connected once Google hands over a refresh token. The token is encrypted at rest and never leaves
 * the model in its array or JSON form.
 *
 * @property int $id
 * @property string $email
 * @property string|null $refresh_token
 * @property string|null $folder_id
 * @property int|null $connected_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['email', 'refresh_token', 'folder_id', 'connected_by'])]
#[Hidden(['refresh_token'])]
class GoogleDriveConnection extends Model
{
    /** @use HasFactory<GoogleDriveConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['refresh_token' => 'encrypted'];
    }

    /**
     * Whether the portal can reach the account's Drive: the address alone isn't enough.
     */
    public function isConnected(): bool
    {
        return $this->refresh_token !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
