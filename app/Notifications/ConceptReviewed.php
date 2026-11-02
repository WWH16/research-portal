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
        $passed = $this->decision === 'passed';
        $replace = ['title' => self::bold('“'.$this->submission->displayTitle().'”')];

        // Written in full for the faculty who read it: what was decided, on which project, and what to do next.
        return (new MailMessage)
            ->subject($passed ? __('Concept proposal passed') : __('Concept proposal returned for revision'))
            ->line($passed
                ? __('The Research Office has reviewed your concept proposal for :title and has **passed** it.', $replace)
                : __('The Research Office has reviewed your concept proposal for :title and has **returned it for revision**.', $replace))
            ->lineIf((bool) $this->remarks, __('**Remarks from the Research Office:** :remarks', ['remarks' => self::plain((string) $this->remarks)]))
            ->line($passed
                ? __('You may now **upload the detailed proposal** for this project. Open the project in the portal to upload it.')
                : __('Please **revise your concept proposal** and upload the revised version in the portal. The Research Office will review it again once it is uploaded.'))
            ->action(__('Open project'), $this->url());
    }

    protected function message(): string
    {
        // Written in full for the faculty who read it: what was decided, on which project, and what to do next.
        return $this->decision === 'passed'
            ? __('The Research Office has passed your concept proposal for “:title”. You may now upload the detailed proposal.', ['title' => $this->submission->displayTitle()])
            : __('The Research Office has returned your concept proposal for “:title” for revision. Please read the remarks on the project page, then upload a revised concept proposal.', ['title' => $this->submission->displayTitle()]);
    }

    protected function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
