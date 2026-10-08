{{--
    A project's stages as a row of steps, with what it needs next underneath.
    Expects: $submission; optional $next = false to leave out the next-step line, as the review panel does,
    where the "To review" callout already says what came in.
--}}
@php
    $current = (int) array_search($submission->status, \App\Models\Submission::STATUSES, true);
    $open = $submission->uploadableStages();
@endphp
<div data-test="project-stages">
    {{-- A bar per stage with its name under it, so the stages fit side by side on phones --}}
    <ol class="grid grid-cols-3 gap-2">
        @foreach (\App\Models\Submission::STATUSES as $index => $stage)
            <li class="flex min-w-0 flex-col gap-1.5" @if ($index === $current) aria-current="step" @endif>
                <span class="h-1.5 rounded-full {{ $index <= $current ? 'bg-isu-green-700' : 'bg-zinc-200' }}" aria-hidden="true"></span>
                {{-- A stage whose document can't be uploaded yet: the concept proposal hasn't passed, or the document before it isn't in --}}
                @php($locked = $index > $current && ! in_array(\App\Models\Submission::STAGE_DOCUMENTS[$stage], $open, true))
                <span @class([
                    'flex min-w-0 items-center gap-1 text-sm',
                    'font-semibold text-zinc-800' => $index === $current,
                    'text-zinc-600' => $index !== $current && ! $locked,
                    'text-zinc-500' => $locked,
                ])>
                    @if ($locked)
                        <flux:icon.lock-closed variant="micro" class="shrink-0" aria-hidden="true" />
                    @endif
                    <span class="truncate">{{ __($stage) }}</span>
                    <span class="sr-only">{{ match (true) { $index < $current => __('(done)'), $index === $current => __('(current stage)'), $locked => __('(locked)'), default => __('(next)') } }}</span>
                </span>
            </li>
        @endforeach
    </ol>

    @if ($next ?? true)
        @php($document = $submission->nextDocument())
        <flux:text class="mt-3 text-sm" data-test="project-next-step">
            @if ($submission->awaiting_review)
                {{ __('The concept proposal is waiting for the Research Office to review. The detailed proposal opens once it passes.') }}
            @elseif (! $submission->concept_passed)
                {{ __('The Research Office returned the concept proposal for revision. Upload a corrected one to send it back for review.') }}
            @elseif ($document)
                {{ __('Next: upload the :document.', ['document' => \Illuminate\Support\Str::lower(__(\App\Models\Submission::DOCUMENTS[$document]))]) }}
            @else
                {{ __('All documents are uploaded.') }}
            @endif
        </flux:text>
    @endif
</div>
