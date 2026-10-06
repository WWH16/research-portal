<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\Proponent;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * One form for a new project and for editing an existing one: the researcher enters the project
 * once, then comes back to add proponents who registered later or to upload the next document.
 */
new #[Title('Research Project')] class extends Component {
    use WithFileUploads;

    public ?Submission $submission = null;

    public string $title = '';
    public string $abstract = '';
    public ?int $category_id = null;
    public string $designation = '';
    public string $start_date = '';
    public string $target_date = '';

    /** @var list<array{study: int|string, user_id: int|string|null, role: string}> */
    public array $proponents = [];

    /** New uploads keyed by stage (concept, detailed, terminal). */
    public array $documents = [];

    /** Similar projects already in the portal, shown before a new one is saved. */
    public array $duplicates = [];

    public bool $ignoreDuplicates = false;

    /** "drive" when the member opened the form from a Research Drive project, so leaving it goes back there. */
    #[Url(except: '')]
    public string $from = '';

    public function mount(?Submission $submission = null): void
    {
        if (! $submission?->exists) {
            $this->submission = null;
            $this->proponents = [['study' => 1, 'user_id' => Auth::id(), 'role' => 'Leader']];

            return;
        }

        $this->submission = $submission;
        $this->fill($submission->only(['title', 'category_id']));
        $this->abstract = (string) $submission->abstract;
        $this->designation = (string) $submission->designation;
        $this->start_date = (string) $submission->start_date?->toDateString();
        $this->target_date = (string) $submission->target_date?->toDateString();
        $this->proponents = $submission->proponents()->orderBy('study')->orderBy('id')->get(['study', 'user_id', 'role'])->toArray();
    }

    public function addProponent(): void
    {
        $this->proponents[] = ['study' => end($this->proponents)['study'] ?? 1, 'user_id' => null, 'role' => 'Staff'];
    }

    public function removeProponent(int $index): void
    {
        unset($this->proponents[$index]);
        $this->proponents = array_values($this->proponents);
    }

    public function submitAnyway(): void
    {
        $this->ignoreDuplicates = true;
        $this->save();
    }

    /**
     * Validate the project, check it isn't already in the portal, then save it with its
     * proponents and any new documents.
     */
    public function save(): void
    {
        if ($this->submission) {
            $this->authorize('update', $this->submission);
        } elseif (! $this->department) {
            // A new project is filed under the member's own department, never one from the form.
            $this->addError('department', __('Your account has no college yet. Contact the Research Office before submitting.'));

            return;
        }

        // Each document opens once the one before it is accepted, and a Completed project takes none until reopened.
        // Locked files are dropped, since a project that moved while the form was open no longer shows their fields.
        if ($locked = array_diff(array_keys($this->documents), $this->uploadable)) {
            $this->documents = Arr::except($this->documents, $locked);
            $this->addError('documents', $this->submission?->acceptsUploads() === false
                ? __('This project is Completed. Ask the Research Office to reopen it for a corrected upload.')
                : __('That document isn’t open yet. Each one opens once the document before it is accepted.'));

            return;
        }

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            // A MySQL TEXT column holds 65,535 bytes, so 10,000 characters fit even when each takes four.
            'abstract' => ['required', 'string', 'max:10000'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'designation' => ['nullable', 'string', 'max:100'],
            // The researcher proposes the dates once; after that only the Research Office moves them, with Change dates on Submissions.
            'start_date' => [$this->submission ? 'exclude' : 'required', 'date', 'after_or_equal:today'],
            'target_date' => [$this->submission ? 'exclude' : 'required', 'date', 'after:start_date'],
            'proponents' => ['required', 'array', 'max:60'],
            'proponents.*.study' => ['required', 'integer', 'between:1,20'],
            'proponents.*.user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'faculty')],
            'proponents.*.role' => ['required', Rule::in(Proponent::ROLES)],
            'documents' => [$this->submission ? 'nullable' : 'required', 'array:'.implode(',', array_keys(Submission::DOCUMENTS))],
            'documents.*' => ['file', 'mimes:pdf,doc,docx', 'max:10240'],
        ], [
            'documents.required' => __('Upload at least one document.'),        ], [
            'category_id' => __('category'),
            'start_date' => __('starting date'),
            'target_date' => __('completion date'),
            'proponents.*.study' => __('study'),
            'proponents.*.user_id' => __('faculty member'),
            'proponents.*.role' => __('role'),
            'documents.*' => __('document'),
        ]);

        $rows = collect($validated['proponents'])->map(fn ($row) => ['study' => (int) $row['study'], 'user_id' => (int) $row['user_id'], 'role' => $row['role']]);

        if ($rows->duplicates(fn ($row) => $row['study'].'-'.$row['user_id'])->isNotEmpty()) {
            $this->addError('proponents', __('A faculty member is listed twice in the same study. Remove one of the rows.'));

            return;
        }

        if (! $rows->contains('user_id', Auth::id())) {
            $this->addError('proponents', __('Add yourself to the proponents list, so the project counts toward your research.'));

            return;
        }

        if (! $this->submission && ! $this->ignoreDuplicates && ($this->duplicates = $this->similarProjects($validated['title'])) !== []) {
            return;
        }

        DB::transaction(function () use ($validated, $rows) {
            $submission = $this->submission ?? Auth::user()->submissions()->make(['department_id' => $this->department->id]);

            $submission->fill([
                'title' => $validated['title'],
                'abstract' => $validated['abstract'],
                'category_id' => $validated['category_id'],
                'designation' => $validated['designation'] ?: null,
                ...Arr::only($validated, ['start_date', 'target_date']),
            ]);

            // What an edit changed, read before saving. Title, dates and category keep their old and new values
            // (the category's name saved now, in case it is renamed later); other fields, like the abstract, are only named.
            $readers = [
                'title' => fn (?string $title) => $title,
                'start_date' => fn ($date) => $date?->toDateString(),
                'target_date' => fn ($date) => $date?->toDateString(),
                'category_id' => fn (?int $id) => Category::find($id)?->name,
            ];
            $dirty = array_keys($submission->getDirty());
            $properties = $this->submission ? array_filter([
                'values' => collect($readers)->only($dirty)->map(fn ($read, string $column) => [$read($submission->getOriginal($column)), $read($submission->{$column})])->all(),
                'changed' => array_values(array_diff($dirty, array_keys($readers))),
                'proponents' => $this->proponentChanges($submission->proponents()->get(['study', 'user_id', 'role'])->toArray(), $rows->all()),
            ]) : [];
            $submission->save();

            $submission->proponents()->delete();
            $submission->proponents()->createMany($rows->all());

            // Read before attaching, which overwrites the paths.
            $replaced = array_values(array_filter(array_keys($this->documents), fn (string $stage) => $submission->{$stage.'_path'} !== null));

            foreach ($this->documents as $stage => $file) {
                $submission->attachDocument($stage, $file);
            }

            // Saving an edit that changed nothing and uploaded nothing is left out of the log.
            if (! $this->submission || $properties !== [] || $this->documents !== []) {
                ActivityLog::record($this->submission ? 'submission.updated' : 'submission.created', $submission, array_filter($properties + [
                    'documents' => array_keys($this->documents),
                    'replaced' => $replaced,
                ]));
            }
        });

        session()->flash('status', match (true) {
            ! $this->submission => __('Proposal submitted. It’s now waiting for the Research Office to review.'),
            $this->documents !== [] => __('Project updated. The new document is waiting for the Research Office to review.'),
            default => __('Project updated.'),
        });

        $this->redirect($this->backUrl, navigate: true);
    }

    /**
     * Who joined, left, or changed role on the proponents list, by name, for the activity log. A study
     * number is added to each name only when the project has more than one study.
     *
     * @param  list<array{study: int, user_id: int, role: string}>  $before
     * @param  list<array{study: int, user_id: int, role: string}>  $after
     * @return array{added?: list<array{string, string}>, removed?: list<array{string, string}>, roles?: list<array{string, string, string}>}
     */
    private function proponentChanges(array $before, array $after): array
    {
        $key = fn (array $row) => $row['study'].'-'.$row['user_id'];
        [$before, $after] = [collect($before)->keyBy($key), collect($after)->keyBy($key)];
        $names = User::whereIn('id', $before->pluck('user_id')->merge($after->pluck('user_id')))->pluck('name', 'id');
        $studies = $before->merge($after)->pluck('study')->unique()->count();
        $name = fn (array $row) => $names[$row['user_id']].($studies > 1 ? ' ('.__('study :number', ['number' => $row['study']]).')' : '');

        return array_filter([
            'added' => $after->diffKeys($before)->map(fn (array $row) => [$name($row), $row['role']])->values()->all(),
            'removed' => $before->diffKeys($after)->map(fn (array $row) => [$name($row), $row['role']])->values()->all(),
            'roles' => $after->intersectByKeys($before)
                ->filter(fn (array $row, string $key) => $row['role'] !== $before[$key]['role'])
                ->map(fn (array $row, string $key) => [$name($row), $before[$key]['role'], $row['role']])
                ->values()->all(),
        ]);
    }

    /**
     * Where Back, Cancel and Save lead: the Drive project the form was opened from, or the Submissions list.
     * Only the literal "drive" is honoured, so the query string can't point anywhere else.
     */
    /**
     * The documents that can be uploaded now. A new project starts with the concept proposal.
     *
     * @return list<string>
     */
    #[Computed]
    public function uploadable(): array
    {
        return ($this->submission ?? new Submission)->uploadableStages();
    }

    #[Computed]
    public function backUrl(): string
    {
        return $this->from === 'drive' && $this->submission ? route('drive.show', $this->submission) : route('submissions.index');
    }

    /**
     * Projects whose title is at least 80% alike, so the same project isn't entered and counted twice.
     *
     * @return list<array{title: string, year: int, owner: string, mine: bool}>
     */
    private function similarProjects(string $title): array
    {
        // ponytail: compares against every title in PHP; move to a FULLTEXT search past a few thousand projects.
        return Submission::with('user:id,name')->get(['id', 'title', 'year', 'user_id'])
            ->filter(function (Submission $project) use ($title) {
                similar_text(Str::lower($project->title), Str::lower($title), $percent);

                return $percent >= 80;
            })
            ->map(fn (Submission $project) => [
                'title' => $project->title,
                'year' => $project->year,
                'owner' => $project->user->name,
                'mine' => $project->involves(Auth::user()),
            ])
            ->values()
            ->all();
    }

    /**
     * The signed-in member's department, which every new project they submit is filed under.
     */
    #[Computed]
    public function department(): ?Department
    {
        return $this->submission?->department ?? Auth::user()->department;
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::orderBy('name')->get(['id', 'name']);
    }

    /**
     * Faculty who can be listed as proponents. Co-proponents who haven't registered yet are
     * added later, once they have an account.
     */
    #[Computed]
    public function faculty(): Collection
    {
        // ponytail: one plain select of every verified faculty member; switch to a searchable picker past a few hundred.
        return User::where('role', 'faculty')->whereNotNull('email_verified_at')->orderBy('name')->get(['id', 'name']);
    }
}; ?>

{{-- Same width as the Submissions list and My Profile, so the page doesn't jump when you open it --}}
<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        @php($backLabel = $this->backUrl === route('submissions.index') ? __('Back to Submissions') : __('Back to Research Drive'))
        <flux:button :href="$this->backUrl" variant="ghost" icon="arrow-left" wire:navigate :aria-label="$backLabel" :tooltip="$backLabel" class="-ms-2 shrink-0" data-test="back-to-submissions" />
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <flux:heading size="xl" level="1">{{ $submission ? __('Edit Project') : __('Submit Proposal') }}</flux:heading>
    </header>

    @unless ($this->department)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" :heading="__('Your account has no college yet')" :text="__('Proposals are filed under your college. Contact the Research Office to have one assigned, then come back to submit.')" />
    @endunless

    @if ($submission?->remarks && ! $submission->awaiting_review)
        <flux:callout icon="chat-bubble-left-ellipsis" class="mt-6" :heading="__('Remarks from the Research Office')" :text="$submission->remarks" />
    @endif

    {{-- Only an existing project has a stage; a new proposal starts at Submitted once it's saved --}}
    @if ($submission)
        <div class="mt-6">@include('partials.project-stages', ['submission' => $submission])</div>
    @endif

    <form wire:submit="save" class="mt-8 flex flex-col gap-6">
        <flux:input wire:model="title" :label="__('Title')" type="text" required autofocus />

        <flux:textarea wire:model="abstract" :label="__('Abstract')" rows="6" required />

        {{-- Category, college and designation share one grid, so the filing details read as one group --}}
        <div class="grid gap-6 sm:grid-cols-2">
            {{-- A real, selectable blank option instead of Flux's disabled placeholder: after a re-render the browser
                 can't show a disabled option, so it showed the first real one while nothing was chosen. --}}
            <flux:select wire:model="category_id" :label="__('Category')" required>
                <flux:select.option value="">{{ __('Select category') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                :label="__('College')"
                :value="$this->department ? $this->department->code : __('Not assigned')"
                :badge="$submission ? null : __('From your profile')"
                type="text"
                disabled
            />

            <flux:input wire:model="designation" :label="__('Designation')" :badge="__('Optional')" type="text" />
        </div>

        <div>
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="start_date" :label="__('Starting date')" type="date" :min="$submission ? null : today()->toDateString()" :required="! $submission" :disabled="(bool) $submission" />
                <flux:input wire:model="target_date" :label="__('Completion date')" type="date" x-bind:min="$wire.start_date && new Date(Date.parse($wire.start_date) + 864e5).toISOString().slice(0, 10)" :required="! $submission" :disabled="(bool) $submission" />
            </div>
            <flux:text class="mt-2 text-sm">
                {{ $submission
                    ? __('Only the Research Office can change these dates. Contact them if the project needs more time.')
                    : __('As proposed. The project shows as delayed if no terminal report is in by the completion date.') }}
            </flux:text>
        </div>

        <flux:fieldset>
            <flux:legend>{{ __('Proponents') }}</flux:legend>
            <flux:description>{{ __('List everyone on each study, as in your proposal’s proponents table. Co-proponents without an account yet can be added later.') }}</flux:description>

            <div class="mt-4 grid gap-3">
                <div class="grid grid-cols-[4.5rem_minmax(0,1fr)_7.5rem_2.5rem] gap-2 text-sm font-medium text-zinc-700" aria-hidden="true">
                    <span>{{ __('Study') }}</span>
                    <span>{{ __('Faculty') }}</span>
                    <span>{{ __('Role') }}</span>
                </div>

                @foreach ($proponents as $index => $row)
                    <div class="grid grid-cols-[4.5rem_minmax(0,1fr)_7.5rem_2.5rem] items-start gap-2" wire:key="proponent-{{ $index }}" data-test="proponent-row">
                        <flux:input wire:model="proponents.{{ $index }}.study" type="number" min="1" max="20" :aria-label="__('Study')" />

                        <flux:select wire:model="proponents.{{ $index }}.user_id" :aria-label="__('Faculty')">
                            <flux:select.option value="">{{ __('Select faculty') }}</flux:select.option>
                            @foreach ($this->faculty as $member)
                                <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="proponents.{{ $index }}.role" :aria-label="__('Role')">
                            @foreach (Proponent::ROLES as $role)
                                <flux:select.option :value="$role">{{ __($role) }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:button variant="ghost" icon="x-mark" wire:click="removeProponent({{ $index }})" :aria-label="__('Remove proponent')" :disabled="count($proponents) === 1" />
                    </div>
                @endforeach
            </div>

            <flux:error name="proponents" class="mt-2" />

            <flux:button size="sm" icon="plus" wire:click="addProponent" class="mt-3" data-test="add-proponent-button">{{ __('Add proponent') }}</flux:button>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Documents') }}</flux:legend>
            @php($uploadsOpen = $submission?->acceptsUploads() ?? true)
            @if ($uploadsOpen)
                <flux:description>
                    {{ $submission
                        ? __('Upload the next document here when it’s ready. A new upload replaces the earlier file and goes back to the Research Office for review.')
                        : __('Start with the concept proposal. The detailed proposal and terminal report open as each earlier document is accepted.') }}
                </flux:description>
            @else
                <flux:callout icon="lock-closed" class="mt-2" :heading="__('Uploads are closed')" :text="__('This project is Completed. Ask the Research Office to reopen it if a document needs correcting.')" />
            @endif

            <div class="mt-4 grid gap-4">
                @foreach (Submission::DOCUMENTS as $stage => $label)
                    {{-- Closed uploads still list the files on record, so they can be opened --}}
                    @continue (! $uploadsOpen && ! $submission->{$stage.'_path'})
                    <div>
                        @if (in_array($stage, $this->uploadable, true))
                            <flux:input
                                wire:model="documents.{{ $stage }}"
                                :label="__($label)"
                                :description:trailing="__('PDF or Word, up to 10 MB')"
                                type="file"
                                accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                            />
                        @else
                            <flux:heading size="sm">{{ __($label) }}</flux:heading>
                            {{-- Locked until the document before it is accepted; a closed Completed project says so above instead --}}
                            @if ($uploadsOpen)
                                <flux:text class="mt-1 flex items-center gap-1.5 text-sm" data-test="document-locked">
                                    <flux:icon.lock-closed variant="micro" class="shrink-0" />
                                    {{ __('Opens after the :document is accepted.', ['document' => Str::lower(__(Submission::DOCUMENTS[array_keys(Submission::DOCUMENTS)[$loop->index - 1]]))]) }}
                                </flux:text>
                            @endif
                        @endif

                        @if ($submission?->{$stage.'_path'})
                            <flux:link :href="route('submissions.document', [$submission, $stage])" target="_blank" rel="noopener" class="mt-2 inline-block text-sm">{{ __('Open current file') }}</flux:link>
                        @endif
                    </div>
                @endforeach
            </div>

            <flux:error name="documents" class="mt-2" />

            <flux:text wire:loading wire:target="documents" class="mt-2 text-sm">
                {{ __('Uploading…') }}
            </flux:text>
        </flux:fieldset>

        @if ($duplicates)
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="duplicate-warning">
                <flux:callout.heading>{{ __('A similar project is already in the portal') }}</flux:callout.heading>
                <flux:callout.text>
                    <ul class="grid gap-1">
                        @foreach ($duplicates as $duplicate)
                            <li>
                                “{{ $duplicate['title'] }}” ({{ $duplicate['year'] }}), {{ __('filed by :name', ['name' => $duplicate['owner']]) }}.
                                @if ($duplicate['mine'])
                                    <strong>{{ __('You are listed as a proponent.') }}</strong>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2">{{ __('Submitting it again counts the same project twice. Check with your co-proponents first.') }}</p>
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" :href="$this->backUrl" wire:navigate>{{ __('Cancel') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="submitAnyway" data-test="submit-anyway-button">{{ __('Submit anyway') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        <div class="flex items-center justify-end gap-3 border-t border-line pt-6">
            <flux:button :href="$this->backUrl" variant="ghost" wire:navigate>
                {{ __('Cancel') }}
            </flux:button>

            <flux:error name="department" class="me-auto" />

            {{-- Flux drops its built-in spinner when a :disabled binding is present, so turn it back on; skipped when
                 there's no college, because Flux shows the spinner on any disabled submit button. --}}
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,documents" :disabled="! $this->department" :loading="(bool) $this->department" data-test="submit-proposal-button">
                {{ $submission ? __('Save') : __('Submit') }}
            </flux:button>
        </div>
    </form>
</section>
