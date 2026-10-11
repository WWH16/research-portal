<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the Research Office that a concept proposal came in and waits for a decision.
 */
class ConceptAwaitingReview extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Submission $submission,
        public readonly string $uploader,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        // An address the member hasn't confirmed gets no mail; the notice still waits in the portal.
        return $notifiable->hasVerifiedEmail() ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Concept proposal to review'))
            ->line($this->message())
            ->action(__('Review it'), $this->url());
    }

    /**
     * @return array{submission_id: int, message: string, url: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['submission_id' => $this->submission->id, 'message' => $this->message(), 'url' => $this->url()];
    }

    private function message(): string
    {
        return __('New concept proposal to review: :title, from :name.', ['title' => $this->submission->displayTitle(), 'name' => $this->uploader]);
    }

    private function url(): string
    {
        return route('reviews.index', ['review' => $this->submission->id]);
    }
}
