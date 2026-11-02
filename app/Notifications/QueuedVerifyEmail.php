<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's verification email, sent through the queue like every other portal email, so signing up, changing an
 * email or pressing Resend never waits on Gmail, and a mail server that is down is tried again instead of showing
 * an error. Its wording comes from VerifyEmail::toMailUsing() in FortifyServiceProvider.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;
}
