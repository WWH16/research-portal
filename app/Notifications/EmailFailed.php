<?php

namespace App\Notifications;

/**
 * Tells the Research Office an email failed all its tries, so a broken mail setup doesn't go unnoticed in the
 * server logs. Portal only, since email is what's broken.
 */
class EmailFailed extends PortalNotification
{
    protected const bool EMAILED = false;

    protected function message(): string
    {
        return __('An email from the portal could not be sent after 3 tries, so it did not reach its recipient. Please check the mail settings on Laravel Cloud, then run php artisan queue:retry all to send the failed emails again.');
    }
}
