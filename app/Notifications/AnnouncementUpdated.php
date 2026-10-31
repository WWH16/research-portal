<?php

namespace App\Notifications;

/**
 * Tells faculty the Research Office changed an announcement, such as a moved deadline or a new memo. Portal only, like
 * the notice of the post itself, and it links to the same place.
 */
class AnnouncementUpdated extends AnnouncementPosted
{
    protected const string OPENING = 'The Research Office has updated the announcement “:title”.';
}
