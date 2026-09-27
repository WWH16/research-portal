<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'research_type_id',
    'category_id',
    'department_id',
    'title',
    'abstract',
    'designation',
    'file_path',
    'status',
    'remarks',
])]
class Submission extends Model
{
    /** The review states, in the order an admin moves through them. Matches the column's enum. */
    public const STATUSES = ['Pending', 'For Revision', 'OK'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function researchType(): BelongsTo
    {
        return $this->belongsTo(ResearchType::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
