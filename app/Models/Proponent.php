<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a project's proponents table: a faculty member's role in one study.
 */
#[Fillable(['user_id', 'study', 'role'])]
class Proponent extends Model
{
    /** Roles a faculty member can hold in a study, as the proposal's proponents table lists them. */
    public const ROLES = ['Leader', 'Co-Leader', 'Staff'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
