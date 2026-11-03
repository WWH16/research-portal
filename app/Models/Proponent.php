<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a project's proponents table: a person's role in one study. Faculty are linked by account; staff and
 * people outside the campus have no account, so their row holds only the full name a co-proponent typed.
 *
 * @property int|null $user_id
 * @property string|null $name
 */
#[Fillable(['user_id', 'name', 'study', 'role'])]
class Proponent extends Model
{
    /** Roles a faculty member can hold in a study, as the proposal's proponents table lists them. */
    public const ROLES = ['Leader', 'Co-Leader', 'Staff'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The full name as messages print it, from the account when there is one.
     */
    public function displayName(): string
    {
        return $this->user?->displayName() ?? (string) $this->name;
    }

    /**
     * The name as lists print it: "John Ansley Rocel" reads "JA Rocel".
     */
    public function shortName(): string
    {
        return $this->user?->shortName() ?? Publication::shortName((string) $this->name);
    }

    /**
     * Who the row is, for telling people apart: the account, or the typed name in any case.
     */
    public function personKey(): string
    {
        return $this->user_id !== null ? 'user:'.$this->user_id : 'name:'.mb_strtolower((string) $this->name);
    }
}
