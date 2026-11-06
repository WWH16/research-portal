<?php

namespace App\Policies;

use App\Models\Publication;
use App\Models\User;

class PublicationPolicy
{
    /**
     * The Research Office opens every publication to check it; faculty open the ones they wrote.
     */
    public function view(User $user, Publication $publication): bool
    {
        return $user->isResearchOffice() || $publication->authoredBy($user);
    }

    /**
     * Faculty record their own papers. The Research Office only reads them.
     */
    public function create(User $user): bool
    {
        return $user->isFaculty();
    }

    /**
     * Any portal author can edit the paper and its citing papers. Admins never edit the researcher's entry.
     */
    public function update(User $user, Publication $publication): bool
    {
        return $user->isFaculty() && $publication->authoredBy($user);
    }

    public function delete(User $user, Publication $publication): bool
    {
        return $this->update($user, $publication);
    }
}
