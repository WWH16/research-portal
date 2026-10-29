<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a project's people that the Research Office passed its concept proposal or returned it for revision.
 */
class ConceptReviewed extends PortalNotification
{
    protected const ?string EMAIL_KIND = 'reviews';

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

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->decision === 'passed' ? __('Concept proposal passed') : __('Concept proposal returned for revision'))
            ->line(self::plain($this->message()));

        if ($this->remarks) {
            $mail->line(self::plain(__('Remarks: :remarks', ['remarks' => $this->remarks])));
        }

        return $mail->action(__('Open project'), $this->url());
    }

    protected function message(): string
    {
        // Written in full for the faculty who read it: what was decided, on which project, and what to do next.
        return $this->decision === 'passed'
            ? __('The Research Office has passed your concept proposal for “:title”. You may now upload the detailed proposal.', ['title' => $this->submission->displayTitle()])
            : __('The Research Office has returned your concept proposal for “:title” for revision. Please read the remarks and upload a revised concept proposal.', ['title' => $this->submission->displayTitle()]);
    }

    protected function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
