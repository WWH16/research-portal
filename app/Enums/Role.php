<?php

namespace App\Enums;

/**
 * An account's role, stored in users.role. Admins run the Research Office's portal. Staff are Research Office
 * members who prepare the reports sent to the main campus in Echague, so they see and export everything but change
 * almost nothing. Faculty are the proponents.
 */
enum Role: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Faculty = 'faculty';

    /**
     * The role's name as members read it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Staff => 'Staff',
            self::Faculty => 'Faculty',
        };
    }
}
