<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A research type older projects were filed with. Proposals no longer pick one, so nothing reads these
 * rows; they stay for the projects that point at them.
 */
#[Fillable(['name'])]
class ResearchType extends Model {}
