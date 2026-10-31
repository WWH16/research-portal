<?php

namespace App\Notifications;

use App\Models\Announcement;

/**
 * Tells faculty the Research Office posted a schedule of submissions or a memo. Portal only: the portal's free Gmail
 * account sends about 500 emails a day, and one announcement to every member would use most of them. See
 * docs/announcements.md for turning email on later.
 */
class AnnouncementPosted extends PortalNotification
{
    protected const bool EMAILED = false;

    public function __construct(public readonly Announcement $announcement) {}

    protected function message(): string
    {
        $replace = ['title' => $this->announcement->title, 'date' => $this->announcement->due_on?->format('F j, Y')];

        return implode(' ', array_filter([
            __('The Research Office has posted a new announcement: “:title”.', $replace),
            $this->announcement->due_on ? __('The deadline is :date.', $replace) : null,
            $this->announcement->attachment_path ? __('A memo is attached.') : null,
        ]));
    }

    protected function url(): string
    {
        // The id in the query opens the page of the list that holds it; the one in the fragment scrolls to it there.
        // The Announcements page also finds a member's notice for an announcement by that fragment, to mark it New.
        return route('announcements.index', ['show' => $this->announcement->id]).'#announcement-'.$this->announcement->id;
    }
}
