{{--
    A project's stages as a row of steps, with what it needs next underneath.
    Expects: $submission; optional $next = false to leave out the next-step line, as the review panel does,
    where the "To review" callout already says what came in.
--}}
@php($current = $submission->stageIndex())
<div data-test="project-stages">
    {{-- A bar per stage with its name under it, so four stages fit side by side on phones --}}
    <ol class="grid grid-cols-4 gap-2">
        @foreach (\App\Models\Submission::STATUSES as $index => $stage)
            <li class="flex min-w-0 flex-col gap-1.5" @if ($index === $current) aria-current="step" @endif>
                <span class="h-1.5 rounded-full {{ $index <= $current ? 'bg-isu-green-700' : 'bg-zinc-200' }}" aria-hidden="true"></span>
                <span @class([
                    'truncate text-sm',
                    'font-semibold text-zinc-800' => $index === $current,
                    'text-zinc-600' => $index < $current,
                    'text-zinc-400' => $index > $current,
                ])>
                    {{ __($stage) }}
                    <span class="sr-only">{{ match (true) { $index < $current => __('(done)'), $index === $current => __('(current stage)'), default => __('(not reached)') } }}</span>
                </span>
            </li>
        @endforeach
    </ol>

    @if ($next ?? true)
        @php($step = $submission->nextStep())
        <flux:text class="mt-3 text-sm" data-test="project-next-step">
            @if ($step === null && ($reviewing = $submission->documentUnderReview()))
                {{-- A Completed project can still take a corrected upload --}}
                {{ __('The :document is waiting for the Research Office to review.', ['document' => \Illuminate\Support\Str::lower(__(\App\Models\Submission::DOCUMENTS[$reviewing]))]) }}
            @elseif ($step === null && $submission->uploads_reopened)
                {{ __('All stages are done. The Research Office reopened it for a corrected upload.') }}
            @elseif ($step === null)
                {{ __('All stages are done.') }}
            @else
                @php($document = \Illuminate\Support\Str::lower(__(\App\Models\Submission::DOCUMENTS[$step['document']])))
                {{ match ($step['state']) {
                    'review' => __('The :document is waiting for the Research Office to review.', ['document' => $document]),
                    // A review can also move a project back, or stop short of a document already on file, without remarks.
                    'returned' => $submission->remarks
                        ? __('Returned with remarks. Waiting for a corrected :document.', ['document' => $document])
                        : __('The :document was reviewed without moving the project to :stage. Upload a new one when it’s ready.', ['document' => $document, 'stage' => __($step['stage'])]),
                    default => __('Next: the :document, to move to :stage.', ['document' => $document, 'stage' => __($step['stage'])]),
                } }}
            @endif
        </flux:text>
    @endif
</div>
