<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

class AnnouncementPolicy
{
    /**
     * Only the Research Office posts schedules and memos. Every approved member reads them.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Any admin may correct or remove an announcement, not only the one who posted it, since they post for the office.
     */
    public function update(User $user, Announcement $announcement): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $user->isAdmin();
    }
}
