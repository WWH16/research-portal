<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\Proponent;
use App\Models\Submission;
use App\Models\User;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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

    /** The refusal message for each document whose last pick the server refused, keyed by stage, so Save can't report success while one is missing. */
    #[Locked]
    public array $failedUploads = [];

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

        // A refused pick leaves the field empty, or holding an earlier file, so saving now would say "updated"
        // without the document the member just chose. Stop once to say so; the next Save goes ahead without it.
        if ($stage = array_key_first($this->failedUploads)) {
            $this->addError("documents.$stage", $this->failedUploads[$stage]);
            Flux::toast(variant: 'danger', text: $this->failedUploads[$stage].' '.__('Save again to keep your other changes without it.'));
            $this->failedUploads = [];

            return;
        }

        // Each document opens once the one before it is in; the detailed proposal waits for the concept proposal
        // to pass. Locked files are dropped, since the form doesn't show their fields.
        if ($locked = array_diff(array_keys($this->documents), $this->uploadable)) {
            $this->documents = Arr::except($this->documents, $locked);
            $this->addError('documents', __('That document isn’t open yet. The detailed proposal opens once the concept proposal passes review, the mid-year progress report once the detailed proposal is uploaded, and the terminal report once the mid-year progress report is uploaded.'));

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
            'proponents.*.user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'faculty')
                // New names must be verified faculty, as in the picker; one already listed stays even while re-verifying a changed email.
                ->where(fn ($query) => $query->whereNotNull('email_verified_at')->orWhereIn('id', $this->listedIds()))],
            'proponents.*.role' => ['required', Rule::in(Proponent::ROLES)],
            'documents' => [$this->submission ? 'nullable' : 'required', 'array:'.implode(',', array_keys(Submission::DOCUMENTS))],
            'documents.*' => ['file', 'mimes:pdf,doc,docx', 'max:25600'],
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

        // Only the concept proposal goes to the Research Office; the other documents are filed as they come in.
        session()->flash('status', match (true) {
            ! $this->submission => __('Proposal submitted. The concept proposal is now waiting for the Research Office to review.'),
            isset($this->documents['concept']) && $this->submission->awaiting_review => __('Project updated. The new concept proposal is waiting for the Research Office to review.'),
            $this->documents !== [] => __('Project updated. The document is uploaded.'),
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
     * The documents that can be uploaded now: the ones on file and the next. A new project starts with the concept proposal.
     *
     * @return list<string>
     */
    #[Computed]
    public function uploadable(): array
    {
        return ($this->submission ?? new Submission)->uploadableStages();
    }

    /**
     * The largest document that gets through, in MB: the 25 MB rule, or less when PHP's upload_max_filesize
     * or post_max_size is lower, since PHP drops a bigger file before Laravel sees it.
     */
    #[Computed]
    public function maxUploadMb(): int
    {
        return min(25, intdiv(UploadedFile::getMaxFilesize(), 1024 * 1024));
    }

    /**
     * A file refused before it reached the form. Livewire's own message names the field key ("documents.detailed").
     * A file over PHP's limit and a dropped connection look the same here (no errors either way), so name the
     * document and both things to check rather than guess.
     */
    public function _uploadErrored($name, $errorsInJson, $isMultiple): void
    {
        $this->dispatch('upload:errored', name: $name)->self();
        $stage = Str::after($name, 'documents.');

        $message = __('The :document couldn’t be uploaded. Check it is a PDF or Word file of :size MB or less and your connection is on, then try again.', [
            'document' => Str::lower(__(Submission::DOCUMENTS[$stage] ?? 'document')),
            'size' => $this->maxUploadMb,
        ]);
        $this->failedUploads[$stage] = $message;
        Flux::toast(variant: 'danger', text: $message);

        throw ValidationException::withMessages([$name => $message]);
    }

    /**
     * A file that did upload replaces a refused pick for the same document.
     */
    public function updatedDocuments(mixed $value, string $stage): void
    {
        unset($this->failedUploads[$stage]);
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
        return User::where('role', 'faculty')
            ->where(fn ($query) => $query->whereNotNull('email_verified_at')->orWhereIn('id', $this->listedIds()))
            ->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Who is already on the project. They stay listed, and pickable, while re-verifying a changed email.
     *
     * @return list<int>
     */
    private function listedIds(): array
    {
        return $this->submission?->proponents()->pluck('user_id')->all() ?? [];
    }
}; ?>

{{-- Same width as My Profile, so the form doesn't jump between the two --}}
<section class="mx-auto w-full max-w-4xl">
    {{-- A child page, so a breadcrumb back to where it was opened from, like the publication form --}}
    <header class="grid gap-4 border-b border-line pb-6">
        <flux:breadcrumbs class="min-w-0 flex-wrap gap-y-1" data-test="back-to-submissions">
            @if ($this->backUrl === route('submissions.index'))
                <flux:breadcrumbs.item :href="route('submissions.index')" wire:navigate>{{ __('Submissions') }}</flux:breadcrumbs.item>
            @else
                <flux:breadcrumbs.item :href="route('drive.index')" wire:navigate>{{ __('Research Drive') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item :href="$this->backUrl" wire:navigate>{{ str($submission->title)->limit(40) }}</flux:breadcrumbs.item>
            @endif
            <flux:breadcrumbs.item>{{ $submission ? __('Edit') : __('New proposal') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1">{{ $submission ? __('Edit Project') : __('Submit Proposal') }}</flux:heading>
    </header>

    @unless ($this->department)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" :heading="__('Your account has no college yet')" :text="__('Proposals are filed under your college. Contact the Research Office to have one assigned, then come back to submit.')" />
    @endunless

    @includeWhen($submission, 'partials.concept-remarks', ['submission' => $submission])

    {{-- Only an existing project has a stage; a new proposal starts at Concept once it's saved --}}
    @if ($submission)
        <div class="mt-6">@include('partials.project-stages', ['submission' => $submission])</div>
    @endif

    {{-- Livewire sets no loading state while a file is still uploading, so the form counts uploads from their
         events and stops Save (button or Enter) until they finish; otherwise Save beats the file to the server.
         The guard listens in the capture phase so it runs before wire:submit, which still disables the form. --}}
    <form
        wire:submit="save"
        x-data="{ uploading: 0 }"
        x-on:livewire-upload-start="uploading++"
        x-on:livewire-upload-finish="uploading--"
        x-on:livewire-upload-error="uploading--"
        x-on:livewire-upload-cancel="uploading--"
        x-on:submit.capture="uploading && ($event.preventDefault(), $event.stopImmediatePropagation())"
        class="mt-8 flex flex-col gap-6"
    >
        <flux:input wire:model="title" :label="__('Title')" type="text" required autofocus />

        <flux:textarea wire:model="abstract" :label="__('Abstract')" rows="6" required />

        {{-- The filing details sit on the same 12-column grid as the proponents rows, so their edges line up.
             The badge on a label only ever says Optional; anything else about a field goes in its description. --}}
        <div class="grid gap-x-4 gap-y-6 sm:grid-cols-12">
            {{-- A real, selectable blank option instead of Flux's disabled placeholder: after a re-render the browser
                 can't show a disabled option, so it showed the first real one while nothing was chosen. --}}
            <flux:select wire:model="category_id" :label="__('Category')" required field:class="sm:col-span-6">
                <flux:select.option value="">{{ __('Select category') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="designation" :label="__('Designation')" :badge="__('Optional')" type="text" maxlength="100" field:class="sm:col-span-6" />

            {{-- Full width, so a long college name shows in full rather than cut off in half a row --}}
            <flux:input
                :label="__('College')"
                :value="$this->department ? $this->department->name.' ('.$this->department->code.')' : __('Not assigned')"
                :description:trailing="$submission ? null : __('From your profile.')"
                type="text"
                disabled
                field:class="sm:col-span-12"
            />

            <flux:input wire:model="start_date" :label="__('Starting date')" type="date" :min="$submission ? null : today()->toDateString()" :required="! $submission" :disabled="(bool) $submission" field:class="sm:col-span-6" />
            <flux:input
                wire:model="target_date"
                :label="__('Completion date')"
                type="date"
                x-bind:min="$wire.start_date && new Date(Date.parse($wire.start_date) + 864e5).toISOString().slice(0, 10)"
                :required="! $submission"
                :disabled="(bool) $submission"
                field:class="sm:col-span-6"
            />

            @if ($submission)
                <flux:text class="-mt-4 text-sm sm:col-span-12">{{ __('Only the Research Office can change these dates. Contact them if the project needs more time.') }}</flux:text>
            @endif
        </div>

        <flux:fieldset>
            <flux:legend>{{ __('Proponents') }}</flux:legend>
            <flux:description>{{ __('List everyone on each study, as in your proposal’s proponents table. Pick co-proponents by name from faculty with a portal account; anyone who registers later can be added then. Keep study no. 1 unless the project has more than one study.') }}</flux:description>

            {{-- Phones keep a fixed four-column row; from sm up the rows join the form's 12-column grid --}}
            @php($columns = 'grid grid-cols-[4.5rem_minmax(0,1fr)_7.5rem_2.5rem] gap-x-2 sm:grid-cols-12 sm:gap-x-4')
            <div class="mt-4 grid gap-3">
                <div class="{{ $columns }} border-b border-line pb-2 text-sm font-medium text-zinc-700" aria-hidden="true">
                    <span class="sm:col-span-2">{{ __('Study no.') }}</span>
                    <span class="sm:col-span-7">{{ __('Faculty') }}</span>
                    <span class="sm:col-span-2">{{ __('Role') }}</span>
                </div>

                @foreach ($proponents as $index => $row)
                    <div class="{{ $columns }} items-start" wire:key="proponent-{{ $index }}" data-test="proponent-row">
                        <div class="sm:col-span-2">
                            <flux:input wire:model="proponents.{{ $index }}.study" type="number" min="1" max="20" :aria-label="__('Study no.')" />
                        </div>

                        <div class="min-w-0 sm:col-span-7">
                            <flux:select wire:model="proponents.{{ $index }}.user_id" :aria-label="__('Faculty')">
                                <flux:select.option value="">{{ __('Select faculty') }}</flux:select.option>
                                @foreach ($this->faculty as $member)
                                    <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <div class="sm:col-span-2">
                            <flux:select wire:model="proponents.{{ $index }}.role" :aria-label="__('Role')">
                                @foreach (Proponent::ROLES as $role)
                                    <flux:select.option :value="$role">{{ __($role) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <div class="justify-self-end sm:col-span-1">
                            <flux:button variant="ghost" icon="x-mark" wire:click="removeProponent({{ $index }})" :aria-label="__('Remove proponent')" :tooltip="__('Remove proponent')" :disabled="count($proponents) === 1" />
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:error name="proponents" class="mt-2" />

            <flux:button size="sm" icon="plus" wire:click="addProponent" class="mt-3" data-test="add-proponent-button">{{ __('Add proponent') }}</flux:button>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Documents') }}</flux:legend>
            <flux:description>
                {{ $submission
                    ? __('Upload the next document here when it’s ready. A new upload replaces the earlier file. Only the concept proposal goes to the Research Office for review.')
                    : __('Start with the concept proposal. The detailed proposal opens once the Research Office passes it. Each later document opens once the one before it is uploaded.') }}
            </flux:description>

            {{-- The four documents as numbered steps: a check once a file is on record, the step to upload now filled
                 in green, and a grey number on each one that hasn't opened yet. --}}
            @php($current = collect($this->uploadable)->first(fn (string $stage) => ! $submission?->{$stage.'_path'}))
            <ol class="mt-5 grid" data-test="document-steps">
                @foreach (Submission::DOCUMENTS as $stage => $label)
                    @php($open = in_array($stage, $this->uploadable, true))
                    @php($onFile = (bool) $submission?->{$stage.'_path'})
                    <li class="relative flex gap-4 pb-8 last:pb-0" wire:key="document-{{ $stage }}" @if ($stage === $current) aria-current="step" @endif>
                        {{-- The line joining each step to the next --}}
                        @unless ($loop->last)
                            <span class="absolute top-9 bottom-1 left-3.5 w-px bg-line" aria-hidden="true"></span>
                        @endunless

                        <span @class([
                            'flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold tabular-nums',
                            'bg-isu-green-700 text-white' => $stage === $current,
                            'bg-isu-green-50 text-isu-green-800 ring-1 ring-isu-green-200 ring-inset' => $onFile,
                            'bg-white text-zinc-700 ring-1 ring-zinc-300 ring-inset' => $open && ! $onFile && $stage !== $current,
                            'bg-zinc-100 text-zinc-600' => ! $open,
                        ]) aria-hidden="true">
                            @if ($onFile)
                                <flux:icon.check variant="micro" />
                            @else
                                {{ $loop->iteration }}
                            @endif
                        </span>

                        <div class="min-w-0 flex-1 pt-0.5">
                            <span @class(['text-sm', 'font-semibold text-zinc-900' => $open, 'font-medium text-zinc-700' => ! $open])>{{ __($label) }}</span>

                            @if ($open)
                                {{-- The file input covers the whole box, transparent, so a click anywhere opens the picker and a
                                     dropped file lands on it natively. Alpine only swaps the words to name the file. --}}
                                @php($prompt = $onFile ? __('Drop a new file here to replace it, or click to choose') : __('Drop the file here, or click to choose'))
                                <label
                                    x-data="{ name: '', over: false, prompt: @js($prompt) }"
                                    x-bind:class="over && 'border-isu-green-600! bg-isu-green-50!'"
                                    class="relative mt-3 flex flex-col items-center gap-1 rounded-lg border border-dashed border-zinc-300 bg-zinc-50 px-4 py-6 text-center transition-colors hover:border-isu-green-600 hover:bg-isu-green-50 has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-isu-green-700"
                                    data-test="dropzone-{{ $stage }}"
                                >
                                    <input
                                        wire:model="documents.{{ $stage }}"
                                        type="file"
                                        accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                        aria-label="{{ $onFile ? __('Replace the :document', ['document' => Str::lower(__($label))]) : __('Upload the :document', ['document' => Str::lower(__($label))]) }}"
                                        aria-describedby="documents-{{ $stage }}-hint"
                                        x-on:change="name = $event.target.files[0]?.name ?? ''"
                                        x-on:dragenter="over = true"
                                        x-on:dragleave="over = false"
                                        x-on:drop="over = false"
                                        class="absolute inset-0 size-full cursor-pointer opacity-0"
                                    />
                                    <flux:icon.arrow-up-tray class="size-5 text-zinc-500" aria-hidden="true" />
                                    <span class="text-sm font-medium break-all text-zinc-800" wire:loading.remove wire:target="documents.{{ $stage }}" x-text="name || prompt">{{ $prompt }}</span>
                                    <span class="text-sm font-medium break-all text-zinc-800" wire:loading wire:target="documents.{{ $stage }}" x-text="@js(__('Uploading')) + ' ' + name + '…'"></span>
                                    <span id="documents-{{ $stage }}-hint" class="text-xs text-zinc-600">{{ __('PDF or Word, up to :size MB', ['size' => $this->maxUploadMb]) }}</span>
                                </label>

                                <flux:error name="documents.{{ $stage }}" class="mt-2" />
                            @else
                                <flux:text class="mt-1 text-sm text-zinc-600" data-test="document-locked">
                                    {{ match ($stage) {
                                        'detailed' => __('Opens after the Research Office passes the concept proposal.'),
                                        'midyear' => __('Opens after the detailed proposal is uploaded.'),
                                        default => __('Opens after the mid-year progress report is uploaded.'),
                                    } }}
                                </flux:text>
                            @endif

                            @if ($onFile)
                                <flux:link :href="route('submissions.document', [$submission, $stage])" target="_blank" rel="noopener" class="mt-2 inline-block text-sm">{{ __('Open current file') }}</flux:link>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>

            <flux:error name="documents" :deep="false" class="mt-2" />

            <flux:text x-show="uploading" x-cloak class="mt-2 text-sm" data-test="uploading">
                {{ __('Uploading… Save waits until the file is in.') }}
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
