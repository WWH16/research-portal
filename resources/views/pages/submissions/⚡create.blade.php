<?php

use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Submit Proposal')] class extends Component {
    use WithFileUploads;

    public string $title = '';
    public string $abstract = '';
    public ?int $research_type_id = null;
    public ?int $department_id = null;
    public ?int $category_id = null;
    public string $designation = '';

    /** @var TemporaryUploadedFile|null */
    public $document = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->department_id = Auth::user()->department_id;
    }

    /**
     * Validate the proposal, store its PDF privately, and create the submission.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'abstract' => ['required', 'string'],
            'research_type_id' => ['required', 'integer', 'exists:research_types,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'designation' => ['nullable', 'string', 'max:100'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ], attributes: [
            'research_type_id' => __('research type'),
            'department_id' => __('department'),
            'category_id' => __('category'),
            'document' => __('proposal PDF'),
        ]);

        $path = $this->document->store('submissions', 'local');

        Auth::user()->submissions()->create([
            'research_type_id' => $validated['research_type_id'],
            'department_id' => $validated['department_id'],
            'category_id' => $validated['category_id'],
            'title' => $validated['title'],
            'abstract' => $validated['abstract'],
            'designation' => $validated['designation'] ?: null,
            'file_path' => $path,
        ]);

        session()->flash('status', __('Proposal submitted.'));

        $this->redirectRoute('submissions.index', navigate: true);
    }

    #[Computed]
    public function researchTypes(): Collection
    {
        return ResearchType::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function departments(): Collection
    {
        return Department::orderBy('name')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::orderBy('name')->get(['id', 'name']);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_logo.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <flux:heading size="xl" level="1">{{ __('Submit Proposal') }}</flux:heading>
    </header>

    <form wire:submit="save" class="mt-8 flex flex-col gap-6">
        <flux:input wire:model="title" :label="__('Title')" type="text" required autofocus />

        <flux:textarea wire:model="abstract" :label="__('Abstract')" rows="6" required />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:select wire:model="research_type_id" :label="__('Research type')" :placeholder="__('Select type')" required>
                @foreach ($this->researchTypes as $type)
                    <flux:select.option :value="$type->id">{{ $type->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="category_id" :label="__('Category')" :placeholder="__('Select category')" required>
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:select wire:model="department_id" :label="__('Department')" :placeholder="__('Select department')" required>
                @foreach ($this->departments as $department)
                    <flux:select.option :value="$department->id">{{ $department->code }} · {{ $department->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="designation" :label="__('Designation')" :badge="__('Optional')" type="text" />
        </div>

        <div>
            <flux:input
                wire:model="document"
                :label="__('Proposal PDF')"
                :description:trailing="__('PDF, up to 10 MB')"
                type="file"
                accept="application/pdf,.pdf"
                required
            />

            <flux:text wire:loading wire:target="document" class="mt-2 text-sm">
                {{ __('Uploading…') }}
            </flux:text>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-line pt-6">
            <flux:button :href="route('submissions.index')" variant="ghost" wire:navigate>
                {{ __('Cancel') }}
            </flux:button>

            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,document" data-test="submit-proposal-button">
                {{ __('Submit') }}
            </flux:button>
        </div>
    </form>
</section>
