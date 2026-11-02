<?php

namespace App\Enums;

/**
 * An account's role, stored in users.role. Admins are the Research Office's staff; faculty are the proponents.
 */
enum Role: string
{
    case Admin = 'admin';
    case Faculty = 'faculty';
}
