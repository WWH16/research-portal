<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a project's people that the Research Office passed its concept proposal or returned it for revision.
 */
class ConceptReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    /**
     * The decision and remarks are passed in, not read from the project, so a later upload can't change what the email says.
     *
     * @param  'passed'|'returned'  $decision
     */
    public function __construct(
        public readonly Submission $submission,
        public readonly string $decision,
        public readonly ?string $remarks,
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
        $mail = (new MailMessage)
            ->subject($this->decision === 'passed' ? __('Concept proposal passed') : __('Concept proposal returned for revision'))
            ->line($this->message());

        if ($this->remarks) {
            $mail->line(__('Remarks: :remarks', ['remarks' => $this->remarks]));
        }

        return $mail->action(__('Open project'), $this->url());
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
        return $this->decision === 'passed'
            ? __('Concept proposal passed: :title. You can now upload the detailed proposal.', ['title' => $this->submission->displayTitle()])
            : __('Concept proposal returned for revision: :title.', ['title' => $this->submission->displayTitle()]);
    }

    private function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
