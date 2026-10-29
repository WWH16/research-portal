<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the Research Office that a concept proposal came in and waits for a decision. One uploaded again over an
 * earlier one says it's corrected, so the two notices don't read as a duplicate.
 */
class ConceptAwaitingReview extends PortalNotification
{
    protected const ?string EMAIL_KIND = 'to_review';

    /**
     * Uploaded again over an earlier one. A plain property with a default, not a promoted one, so a notice queued
     * before this was recorded still sends, reading as new.
     */
    public bool $corrected = false;

    public function __construct(
        public readonly Submission $submission,
        public readonly string $uploader,
        bool $corrected = false,
    ) {
        $this->corrected = $corrected;
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Concept proposal to review'))
            ->line(self::plain($this->message()))
            ->action(__('Review it'), $this->url());
    }

    protected function message(): string
    {
        $replace = ['title' => $this->submission->displayTitle(), 'name' => $this->uploader];

        return $this->corrected
            ? __('Corrected concept proposal to review: :title, from :name.', $replace)
            : __('New concept proposal to review: :title, from :name.', $replace);
    }

    protected function url(): string
    {
        return route('reviews.index', ['review' => $this->submission->id]);
    }
}
