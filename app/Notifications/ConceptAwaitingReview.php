<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the Research Office that a concept proposal came in and waits for a decision.
 */
class ConceptAwaitingReview extends PortalNotification
{
    public function __construct(
        public readonly Submission $submission,
        public readonly string $uploader,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Concept proposal to review'))
            ->line(self::plain($this->message()))
            ->action(__('Review it'), $this->url());
    }

    protected function message(): string
    {
        return __('New concept proposal to review: :title, from :name.', ['title' => $this->submission->displayTitle(), 'name' => $this->uploader]);
    }

    protected function url(): string
    {
        return route('reviews.index', ['review' => $this->submission->id]);
    }
}
