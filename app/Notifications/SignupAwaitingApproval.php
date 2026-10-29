<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Tells the Research Office that a sign-up confirmed its email and waits for approval. Portal only:
 * the sidebar count already keeps the waiting list in sight, so it isn't worth an email.
 */
class SignupAwaitingApproval extends PortalNotification
{
    public function __construct(
        public readonly User $user,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    protected function message(): string
    {
        return __('New sign-up waiting for approval: :name (:email).', ['name' => $this->user->shortName(), 'email' => $this->user->email]);
    }

    protected function url(): string
    {
        return route('users.index', ['status' => 'pending']);
    }
}
