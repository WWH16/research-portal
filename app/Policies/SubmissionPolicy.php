<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;

class SubmissionPolicy
{
    /**
     * Admins review every project; faculty open the ones they filed or are listed on.
     */
    public function view(User $user, Submission $submission): bool
    {
        return $user->isAdmin() || $submission->involves($user);
    }

    /**
     * Any faculty member on the project can edit its details, proponents and documents.
     * Admins review instead, so they never edit the researcher's entry.
     */
    public function update(User $user, Submission $submission): bool
    {
        return ! $user->isAdmin() && $submission->involves($user);
    }
}
