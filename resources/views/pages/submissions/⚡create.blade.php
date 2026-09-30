<?php

use App\Models\Category;
use App\Models\Department;
use App\Models\Proponent;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
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
    public ?int $research_type_id = null;
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

    public function mount(?Submission $submission = null): void
    {
        if (! $submission?->exists) {
            $this->submission = null;
            $this->proponents = [['study' => 1, 'user_id' => Auth::id(), 'role' => 'Leader']];

            return;
        }

        $this->submission = $submission;
        $this->fill($submission->only(['title', 'research_type_id', 'category_id']));
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

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'abstract' => ['required', 'string'],
            'research_type_id' => ['required', 'integer', 'exists:research_types,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'designation' => ['nullable', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'target_date' => ['required', 'date', 'after:start_date'],
            'proponents' => ['required', 'array', 'max:60'],
            'proponents.*.study' => ['required', 'integer', 'between:1,20'],
            'proponents.*.user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'faculty')],
            'proponents.*.role' => ['required', Rule::in(Proponent::ROLES)],
            'documents' => [$this->submission ? 'nullable' : 'required', 'array:'.implode(',', array_keys(Submission::DOCUMENTS))],
            'documents.*' => ['file', 'mimes:pdf,doc,docx', 'max:10240'],
        ], [
            'documents.required' => __('Upload at least one document.'),
        ], [
            'research_type_id' => __('research type'),
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
            $this->addError('proponents', __('A faculty member is listed twice in the same study.'));

            return;
        }

        if (! $rows->contains('user_id', Auth::id())) {
            $this->addError('proponents', __('List yourself as a proponent, or you lose access to this project.'));

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
                'research_type_id' => $validated['research_type_id'],
                'category_id' => $validated['category_id'],
                'designation' => $validated['designation'] ?: null,
                'start_date' => $validated['start_date'],
                'target_date' => $validated['target_date'],
            ])->save();

            $submission->proponents()->delete();
            $submission->proponents()->createMany($rows->all());

            foreach ($this->documents as $stage => $file) {
                $submission->attachDocument($stage, $file);
            }
        });

        session()->flash('status', $this->submission ? __('Project updated.') : __('Proposal submitted.'));

        $this->redirectRoute('submissions.index', navigate: true);
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

    #[Computed]
    public function researchTypes(): Collection
    {
        return ResearchType::orderBy('name')->get(['id', 'name']);
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

<section class="mx-auto w-full max-w-2xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <flux:heading size="xl" level="1">{{ $submission ? __('Edit Project') : __('Submit Proposal') }}</flux:heading>
    </header>

    @unless ($this->department)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" :heading="__('Your account has no college yet')" :text="__('Proposals are filed under your college. Contact the Research Office to have one assigned, then come back to submit.')" />
    @endunless

    @if ($submission?->remarks)
        <flux:callout icon="chat-bubble-left-ellipsis" class="mt-6" :heading="__('Remarks from the Research Office')" :text="$submission->remarks" />
    @endif

    <form wire:submit="save" class="mt-8 flex flex-col gap-6">
        <flux:input wire:model="title" :label="__('Title')" type="text" required autofocus />

        <flux:textarea wire:model="abstract" :label="__('Abstract')" rows="6" required />

        {{-- Real, selectable blank options instead of Flux's disabled placeholder: after a re-render the browser
             can't show a disabled option, so it showed the first real one while nothing was chosen. --}}
        <div class="grid gap-6 sm:grid-cols-2">
            <flux:select wire:model="research_type_id" :label="__('Research type')" required>
                <flux:select.option value="">{{ __('Select type') }}</flux:select.option>
                @foreach ($this->researchTypes as $type)
                    <flux:select.option :value="$type->id">{{ $type->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="category_id" :label="__('Category')" required>
                <flux:select.option value="">{{ __('Select category') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
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
                <flux:input wire:model="start_date" :label="__('Starting date')" type="date" required />
                <flux:input wire:model="target_date" :label="__('Completion date')" type="date" required />
            </div>
            <flux:text class="mt-2 text-sm">{{ __('As proposed. The project shows as delayed if no terminal report is in by the completion date.') }}</flux:text>
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
            <flux:description>
                {{ $submission
                    ? __('Upload the next document here when it’s ready. A new upload replaces the earlier file and goes back to the Research Office for review.')
                    : __('Upload the document you have now. Add the rest to this same project later.') }}
            </flux:description>

            <div class="mt-4 grid gap-4">
                @foreach (Submission::DOCUMENTS as $stage => $label)
                    <div>
                        <flux:input
                            wire:model="documents.{{ $stage }}"
                            :label="__($label)"
                            :description:trailing="__('PDF or Word, up to 10 MB')"
                            type="file"
                            accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                        />

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
                    <flux:button size="sm" :href="route('submissions.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="submitAnyway" data-test="submit-anyway-button">{{ __('Submit anyway') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        <div class="flex items-center justify-end gap-3 border-t border-line pt-6">
            <flux:button :href="route('submissions.index')" variant="ghost" wire:navigate>
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
