<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's password reset email, sent through the queue like every other portal email, so Forgot password never
 * waits on Gmail, and a mail server that is down is tried again instead of showing an error. Its wording comes
 * from ResetPassword::toMailUsing() in FortifyServiceProvider.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;
}
