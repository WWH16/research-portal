<?php

use App\Models\Submission;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One project in the Research Drive: status, studies with each proponent's role and college, and
 * the documents by stage. The route checks the view policy, so only the Research Office and the
 * project's own people get here. Editing and reviewing stay on Submissions, but return here when done.
 */
new #[Title('Research Drive')] class extends Component {
    public Submission $submission;

    public function mount(Submission $submission): void
    {
        // Edits and reviews opened from here come back with a confirmation.
        if ($message = session('status')) {
            Flux::toast(variant: 'success', text: $message);
        }

        $this->submission = $submission->load([
            'user:id,name',
            'department:id,code',
            'proponents' => fn ($query) => $query->orderBy('study')->orderBy('id'),
            'proponents.user:id,name,department_id',
            'proponents.user.department:id,code',
        ]);
    }
}; ?>

@php
    $project = $submission;
    $admin = auth()->user()->isAdmin();
    $onTime = $project->completedOnTime();
@endphp

<section class="mx-auto w-full max-w-4xl">
    {{-- Long titles wrap the trail onto a second line instead of running off narrow screens --}}
    <flux:breadcrumbs class="flex-wrap gap-y-1">
        <flux:breadcrumbs.item :href="route('drive.index')" wire:navigate>{{ __('Research Drive') }}</flux:breadcrumbs.item>
        @if ($admin)
            <flux:breadcrumbs.item :href="route('drive.index', ['college' => $project->department->code])" wire:navigate>{{ $project->department->code }}</flux:breadcrumbs.item>
        @endif
        <flux:breadcrumbs.item>{{ str($project->title)->limit(40) }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <header class="mt-4 flex flex-wrap items-start gap-4 border-b border-line pb-6">
        <div class="min-w-0 flex-1 max-sm:basis-full">
            <flux:heading size="xl" level="1" class="text-balance">{{ $project->title }}</flux:heading>
            <div class="mt-3">@include('partials.project-status', ['submission' => $project])</div>
        </div>

        @can('update', $project)
            <flux:button :href="route('submissions.edit', [$project, 'from' => 'drive'])" icon="pencil-square" wire:navigate data-test="drive-edit-button" class="max-sm:h-11 max-sm:flex-1">{{ __('Edit project') }}</flux:button>
        @endcan
        @if ($admin)
            <flux:button :href="route('submissions.index', ['review' => $project->id, 'from' => 'drive'])" variant="primary" wire:navigate data-test="drive-review-button" class="max-sm:h-11 max-sm:flex-1">{{ __('Review') }}</flux:button>
        @endif
    </header>

    <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
        <div>
            <dt class="text-zinc-500">{{ __('College') }}</dt>
            <dd class="mt-1 font-medium text-zinc-800">{{ $project->department->code }}</dd>
        </div>
        <div>
            <dt class="text-zinc-500">{{ __('Year') }}</dt>
            <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $project->year }}</dd>
        </div>
        <div>
            <dt class="text-zinc-500">{{ __('Filed by') }}</dt>
            <dd class="mt-1 font-medium text-zinc-800">{{ $project->user->name }}</dd>
        </div>
        <div>
            <dt class="text-zinc-500">{{ __('Starting date') }}</dt>
            <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $project->start_date?->format('M j, Y') ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-zinc-500">{{ __('Completion date') }}</dt>
            <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $project->target_date?->format('M j, Y') ?? '—' }}</dd>
        </div>
        @if ($onTime !== null)
            <div>
                <dt class="text-zinc-500">{{ __('Finished') }}</dt>
                <dd class="mt-1 font-medium {{ $onTime ? 'text-green-700' : 'text-amber-800' }}">{{ $onTime ? __('On time') : __('Late') }}</dd>
            </div>
        @endif
    </dl>

    <section class="mt-8" aria-labelledby="progress-heading">
        <flux:heading level="2" id="progress-heading">{{ __('Progress') }}</flux:heading>
        <div class="mt-3">@include('partials.project-stages', ['submission' => $project])</div>
    </section>

    @if ($project->remarks && ! $project->awaiting_review)
        <flux:callout icon="chat-bubble-left-ellipsis" class="mt-6" :heading="__('Remarks from the Research Office')" :text="$project->remarks" />
    @endif

    <section class="mt-10" aria-labelledby="documents-heading">
        <flux:heading level="2" id="documents-heading">{{ __('Documents') }}</flux:heading>
        <ul class="mt-3 divide-y divide-line border-y border-line" data-test="drive-documents">
            @foreach (Submission::DOCUMENTS as $stage => $label)
                @php($path = $project->{$stage.'_path'})
                <li class="flex items-center gap-4 py-3">
                    <flux:icon.document-text variant="mini" class="shrink-0 {{ $path ? 'text-isu-green-700' : 'text-zinc-300' }}" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-zinc-800">{{ __($label) }}</p>
                        <p class="text-sm text-zinc-500">
                            @if ($path && Storage::disk('submissions')->exists($path))
                                {{ __('Uploaded :date', ['date' => date('M j, Y', Storage::disk('submissions')->lastModified($path))]) }}
                            @elseif ($path)
                                {{ __('Uploaded') }}
                            @else
                                {{ __('Not uploaded yet') }}
                            @endif
                        </p>
                    </div>
                    @if ($path)
                        <flux:button size="sm" :href="route('submissions.document', [$project, $stage])" target="_blank" rel="noopener" icon:trailing="arrow-top-right-on-square" class="max-sm:h-11 max-sm:px-4">{{ __('Open') }}</flux:button>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section class="mt-10" aria-labelledby="studies-heading">
        <flux:heading level="2" id="studies-heading">{{ __('Studies and proponents') }}</flux:heading>
        <div class="mt-3 grid gap-6" data-test="drive-studies">
            @foreach ($project->proponents->groupBy('study') as $study => $rows)
                <div>
                    <p class="text-sm font-medium text-zinc-500">{{ __('Study :number', ['number' => $study]) }}</p>
                    <ul class="mt-2 divide-y divide-line border-y border-line">
                        @foreach ($rows as $row)
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2.5">
                                <span class="min-w-0 flex-1 font-medium text-zinc-800">{{ $row->user->name }}</span>
                                <span class="w-24 text-sm text-zinc-600">{{ __($row->role) }}</span>
                                <span class="w-20 text-end text-sm tabular-nums text-zinc-500">{{ $row->user->department?->code ?? __('No college') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>
</section>
