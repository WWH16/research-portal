<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * A faculty member's publications profile is for them and the Research Office.
     */
    public function viewPublications(User $viewer, User $faculty): bool
    {
        return $viewer->isResearchOffice() || $viewer->is($faculty);
    }

    /**
     * The list of every faculty member's publications is for the Research Office.
     */
    public function viewAnyPublications(User $viewer): bool
    {
        return $viewer->isResearchOffice();
    }
}
