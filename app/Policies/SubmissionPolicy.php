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
     * Only the Research Office reviews the concept proposal: leaves remarks and clears the review flag.
     */
    public function review(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Admins open every project to review its concept proposal; faculty open the ones they filed or are listed on.
     */
    public function view(User $user, Submission $submission): bool
    {
        return $user->isAdmin() || $submission->involves($user);
    }

    /**
     * Any faculty member on the project can edit its details, proponents and documents.
     * Admins only review the concept proposal, so they never edit the researcher's entry.
     */
    public function update(User $user, Submission $submission): bool
    {
        return ! $user->isAdmin() && $submission->involves($user);
    }
}
