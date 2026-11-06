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
        return $user->isResearchOffice();
    }

    /**
     * Only admins review the concept proposal: leaves remarks and clears the review flag.
     */
    public function review(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Admins and staff tick whether a completed project presented in-house; staff prepare the certificates.
     */
    public function markPresented(User $user): bool
    {
        return $user->isResearchOffice();
    }

    /**
     * The Research Office opens every project; faculty open the ones they filed or are listed on.
     */
    public function view(User $user, Submission $submission): bool
    {
        return $user->isResearchOffice() || $submission->involves($user);
    }

    /**
     * Any faculty member on the project can edit its details, proponents and documents.
     * The Research Office never edits the researcher's entry.
     */
    public function update(User $user, Submission $submission): bool
    {
        return $user->isFaculty() && $submission->involves($user);
    }
}
