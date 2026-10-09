<?php

use App\Models\ActivityLog;
use App\Models\Publication;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One form for a new publication and for editing one. Portal co-authors are tagged on the same record,
 * so a shared paper and its citing papers are entered once and show on every author's profile.
 */
new #[Title('Publications')] class extends Component {
    public ?Publication $publication = null;

    public string $title = '';
    public string $authors = '';
    public string $journal = '';
    public string $volume = '';
    public string $issue = '';
    public string $pages = '';
    public string $published_on = '';
    public string $description = '';
    public string $link = '';
    public ?int $submission_id = null;

    /** @var list<string> Keys of Publication::INDEXES. */
    public array $indexed_in = [];

    /** @var list<int|string|null> The portal faculty who wrote it, one select per row. */
    public array $authorIds = [];

    public function mount(?Publication $publication = null): void
    {
        if (! $publication?->exists) {
            $this->publication = null;
            $this->authorIds = [Auth::id()];

            return;
        }

        $this->publication = $publication;
        $this->fill(collect($publication->only(['title', 'authors', 'journal', 'volume', 'issue', 'pages', 'description', 'link']))->map(fn ($value) => (string) $value)->all());
        $this->published_on = $publication->published_on->toDateString();
        $this->submission_id = $publication->submission_id;
        $this->indexed_in = $publication->indexed_in ?? [];
        $this->authorIds = $publication->faculty()->orderBy('name')->pluck('users.id')->all();
    }

    public function addAuthor(): void
    {
        $this->authorIds[] = null;
    }

    public function removeAuthor(int $index): void
    {
        unset($this->authorIds[$index]);
        $this->authorIds = array_values($this->authorIds);
    }

    public function save(): void
    {
        $this->publication ? $this->authorize('update', $this->publication) : $this->authorize('create', Publication::class);

        // Compared after normalizing, so the same DOI typed another way is still caught as a duplicate.
        $this->link = Publication::normalizeLink($this->link);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'authors' => ['required', 'string', 'max:1000'],
            'journal' => ['required', 'string', 'max:255'],
            'volume' => ['nullable', 'string', 'max:50'],
            'issue' => ['nullable', 'string', 'max:50'],
            'pages' => ['nullable', 'string', 'max:50'],
            'published_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:10000'],
            'link' => ['required', 'url:http,https', 'max:500', Rule::unique('publications', 'link')->ignore($this->publication)],
            'submission_id' => ['nullable', 'integer', Rule::in($this->projects->pluck('id'))],
            'indexed_in' => ['array'],
            'indexed_in.*' => [Rule::in(array_keys(Publication::INDEXES))],
            'authorIds' => ['required', 'array', 'max:30'],
            'authorIds.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('role', 'faculty')
                ->where(fn ($query) => $query->whereNotNull('email_verified_at')->orWhereIn('id', $this->listedIds()))],
        ], [
            'link.unique' => __('This paper is already in the portal. Ask its authors to add you as a co-author.'),
            'authorIds.*.distinct' => __('A faculty member is listed twice. Remove one of the rows.'),
            'submission_id.in' => __('Pick one of your completed projects.'),
        ], [
            'published_on' => __('date published'),
            'link' => __('DOI or link'),
            'submission_id' => __('project'),
            'authorIds.*' => __('author'),
        ]);

        if (! in_array(Auth::id(), array_map('intval', $validated['authorIds']), true)) {
            $this->addError('authorIds', __('Add yourself to the authors, so the paper shows on your profile.'));

            return;
        }

        // A citing paper can't come out before the paper it cites.
        $earliest = $this->publication?->citations()->min('year');
        if ($earliest && (int) substr($validated['published_on'], 0, 4) > $earliest) {
            $this->addError('published_on', __('A citing paper is from :year. The publication date can’t be after that year.', ['year' => $earliest]));

            return;
        }

        // Kept in the list's order, so ticking boxes in another order is not an edit.
        $validated['indexed_in'] = array_values(array_intersect(array_keys(Publication::INDEXES), $validated['indexed_in']));

        $publication = DB::transaction(function () use ($validated) {
            $publication = $this->publication ?? new Publication;
            $publication->fill(array_map(fn ($value) => $value === '' || $value === [] ? null : $value, Arr::except($validated, 'authorIds')));
            $changed = array_keys($publication->getDirty());
            $publication->save();

            $authors = $publication->faculty()->sync(array_map('intval', $validated['authorIds']));
            $authorsChanged = array_filter($authors) !== [];

            // Saving an edit that changed nothing is left out of the log.
            if (! $this->publication || $changed !== [] || $authorsChanged) {
                ActivityLog::record($this->publication ? 'publication.updated' : 'publication.created', $publication);
            }

            return $publication;
        });

        session()->flash('status', $this->publication ? __('Publication updated.') : __('Publication added. Add the papers that cite it below.'));
        $this->redirectRoute('publications.show', $publication, navigate: true);
    }

    /**
     * Delete the publication with its citing papers, after the confirmation dialog.
     */
    public function delete(): void
    {
        $this->authorize('delete', $this->publication);

        DB::transaction(function () {
            ActivityLog::record('publication.deleted', $this->publication, ['citations' => $this->publication->citations()->count()]);
            $this->publication->delete();
        });

        session()->flash('status', __('Publication deleted.'));
        $this->redirectRoute('faculty.show', Auth::user(), navigate: true);
    }

    /**
     * Where Back and Cancel lead: the publication when editing, the member's profile when adding.
     */
    #[Computed]
    public function backUrl(): string
    {
        return $this->publication ? route('publications.show', $this->publication) : route('faculty.show', Auth::user());
    }

    /**
     * Completed projects the member is on, plus the one already linked, so a co-author who isn't on it keeps the link.
     */
    #[Computed]
    public function projects(): Collection
    {
        return Submission::where(fn ($query) => $query
            ->where(fn ($query) => $query->involving(Auth::user())->where('status', 'Completed'))
            ->when($this->publication?->submission_id, fn ($query, $id) => $query->orWhere('id', $id)))
            ->orderBy('title')
            ->get(['id', 'title', 'year']);
    }

    /**
     * Faculty who can be tagged as authors.
     */
    #[Computed]
    public function faculty(): Collection
    {
        // ponytail: one plain select of every verified faculty member, as on the project form; switch to a searchable picker past a few hundred.
        return User::where('role', 'faculty')
            ->where(fn ($query) => $query->whereNotNull('email_verified_at')->orWhereIn('id', $this->listedIds()))
            ->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Who is already tagged. They stay pickable while re-verifying a changed email.
     *
     * @return list<int>
     */
    private function listedIds(): array
    {
        return $this->publication?->faculty()->pluck('users.id')->all() ?? [];
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <flux:button :href="$this->backUrl" variant="ghost" icon="arrow-left" wire:navigate :aria-label="__('Back')" :tooltip="__('Back')" class="-ms-2 shrink-0" />
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <flux:heading size="xl" level="1">{{ $publication ? __('Edit Publication') : __('Add Publication') }}</flux:heading>
    </header>

    <form wire:submit="save" class="mt-8 flex flex-col gap-6">
        <flux:input wire:model="title" :label="__('Title')" type="text" maxlength="255" required autofocus />

        <flux:input wire:model="authors" :label="__('Authors')" :description:trailing="__('Everyone who wrote the paper, in the order printed on it, including authors outside ISU. For example: Rocel, J. A.; Siton, M.')" type="text" maxlength="1000" required />

        <flux:input wire:model="journal" :label="__('Journal')" :description:trailing="__('Where the paper was published: the journal, or the conference proceedings.')" type="text" maxlength="255" required />

        <div>
            <div class="grid gap-6 sm:grid-cols-3">
                <flux:input wire:model="volume" :label="__('Volume')" :badge="__('Optional')" type="text" maxlength="50" />
                <flux:input wire:model="issue" :label="__('Issue')" :badge="__('Optional')" type="text" maxlength="50" />
                <flux:input wire:model="pages" :label="__('Pages')" :badge="__('Optional')" type="text" maxlength="50" />
            </div>
            <flux:text class="mt-2 text-sm">{{ __('Printed on the paper’s first page or the journal’s website, for example Vol. 12, Issue 3, pages 45–60. Leave a box empty if the paper doesn’t have one.') }}</flux:text>
        </div>

        <flux:checkbox.group wire:model="indexed_in" variant="pills" :label="__('Indexed in')" :badge="__('Optional')" :description:trailing="__('The databases that list the journal or proceedings. Check the journal’s website if you’re not sure. Leave all unticked if it isn’t indexed.')" data-test="indexed-in">
            @foreach (Publication::INDEXES as $key => $label)
                <flux:checkbox :value="$key" :label="__($label)" />
            @endforeach
        </flux:checkbox.group>

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="published_on" :label="__('Date published')" :description:trailing="__('If you only know the month or year, pick the first day.')" type="date" :max="today()->toDateString()" required />
            <flux:input wire:model="link" :label="__('DOI or link')" :description:trailing="__('A DOI is the paper’s permanent ID, starting with 10., such as 10.1000/xyz123. It is usually on the first page. No DOI? Paste the paper’s web address.')" type="text" maxlength="500" required />
        </div>

        <flux:textarea wire:model="description" :label="__('Description')" :badge="__('Optional')" :description:trailing="__('A short summary, such as the paper’s abstract.')" rows="4" />

        <flux:select wire:model="submission_id" :label="__('Research project')" :badge="__('Optional')" :description:trailing="__('If the paper reports one of your completed projects in the portal, pick it.')">
            <flux:select.option value="">{{ __('No project') }}</flux:select.option>
            @foreach ($this->projects as $project)
                <flux:select.option :value="$project->id">{{ $project->title }} ({{ $project->year }})</flux:select.option>
            @endforeach
        </flux:select>

        <flux:fieldset>
            <flux:legend>{{ __('Authors with a portal account') }}</flux:legend>
            <flux:description>{{ __('Of the authors above, pick each one who has a portal account, starting with you. The paper shows on each of their profiles, and any of them can edit it. Authors outside the portal stay in the Authors field only.') }}</flux:description>

            <div class="mt-4 grid gap-3">
                @foreach ($authorIds as $index => $id)
                    <div class="grid grid-cols-[minmax(0,1fr)_2.5rem] items-start gap-2" wire:key="author-{{ $index }}" data-test="author-row">
                        <flux:select wire:model="authorIds.{{ $index }}" :aria-label="__('Author')">
                            <flux:select.option value="">{{ __('Select faculty') }}</flux:select.option>
                            @foreach ($this->faculty as $member)
                                <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:button variant="ghost" icon="x-mark" wire:click="removeAuthor({{ $index }})" :aria-label="__('Remove author')" :disabled="count($authorIds) === 1" />
                    </div>
                    <flux:error name="authorIds.{{ $index }}" />
                @endforeach
            </div>

            <flux:error name="authorIds" class="mt-2" />

            <flux:button size="sm" icon="plus" wire:click="addAuthor" class="mt-3">{{ __('Add co-author') }}</flux:button>
        </flux:fieldset>

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-6">
            @if ($publication)
                <flux:modal.trigger name="publication-delete">
                    <flux:button variant="ghost" icon="trash" class="me-auto text-red-700!" data-test="delete-publication-button">{{ __('Delete publication') }}</flux:button>
                </flux:modal.trigger>
            @endif

            <flux:button :href="$this->backUrl" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" data-test="save-publication-button">{{ $publication ? __('Save') : __('Add publication') }}</flux:button>
        </div>
    </form>

    @if ($publication)
        <flux:modal name="publication-delete" class="w-full sm:w-96" aria-labelledby="publication-delete-heading">
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg" id="publication-delete-heading">{{ __('Delete this publication?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ trans_choice('{0} It is removed from every co-author’s profile. This can’t be undone.|{1} Its 1 citing paper is deleted with it, and it is removed from every co-author’s profile. This can’t be undone.|[2,*] Its :count citing papers are deleted with it, and it is removed from every co-author’s profile. This can’t be undone.', $publication->citations()->count()) }}
                    </flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-publication">{{ __('Delete') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
