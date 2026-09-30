<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;

class SubmissionPolicy
{
    /**
     * Seeing every project at once, such as the portal-wide exports, is for the Research Office.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Only the Research Office reviews: sets the status, leaves remarks, clears the review flag.
     */
    public function review(User $user): bool
    {
        return $user->isAdmin();
    }

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
