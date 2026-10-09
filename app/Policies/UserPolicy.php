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
        return $viewer->isAdmin() || $viewer->is($faculty);
    }
}
