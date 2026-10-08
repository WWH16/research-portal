{{-- The concept proposal review flyout for pages using App\Concerns\ReviewsSubmissions --}}
<flux:modal name="review-submission" variant="flyout" wire:close="closeReview" class="w-full max-sm:p-5 sm:w-[30rem]" aria-labelledby="review-submission-heading">
    {{-- Also redraws on full renders, which is free once a closed review forgets its project --}}
    @island(name: 'review', always: true)
    @if ($this->reviewing)
        {{-- Not a form: Enter must never pass a concept proposal by accident, so each decision is its own button --}}
        <div class="flex flex-col gap-6">
            <div class="pe-8">
                <flux:heading size="lg" id="review-submission-heading">{{ $this->reviewing->title }}</flux:heading>
                <flux:text class="mt-1">{{ __('Filed by :name · :college', ['name' => $this->reviewing->user->name, 'college' => $this->reviewing->department->code]) }}</flux:text>
            </div>

            @include('partials.project-stages', ['submission' => $this->reviewing, 'next' => false])

            @php($uploaded = $this->reviewing->uploadTimes()['concept'] ?? null)
            @switch ($this->reviewing->conceptReview())
                @case ('pending')
                    <flux:callout color="blue" icon="document-arrow-up" data-test="review-to-check">
                        <flux:callout.heading>{{ __('To review: Concept proposal') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ $uploaded ? __('Uploaded :date.', ['date' => $uploaded->format('M j, Y, g:i A')]) : '' }}
                            {{ __('Open it, then pass it or return it for revision. The detailed proposal stays locked until it passes.') }}
                        </flux:callout.text>
                    </flux:callout>
                    @break
                @case ('passed')
                    <flux:callout color="green" icon="check-circle" data-test="review-nothing-new">
                        <flux:callout.heading>{{ __('Concept proposal passed') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('The proponents can upload the detailed proposal. A replaced concept proposal stays passed.') }}</flux:callout.text>
                    </flux:callout>
                    @break
                @default
                    <flux:callout color="amber" icon="arrow-uturn-left" data-test="review-nothing-new">
                        <flux:callout.heading>{{ __('Returned for revision') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Waiting for the proponents to upload a corrected concept proposal.') }}</flux:callout.text>
                    </flux:callout>
            @endswitch

            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                {{-- Full width, so year and encoded date, then the two project dates, stay paired below --}}
                <div class="col-span-2">
                    <dt class="text-zinc-500">{{ __('Category') }}</dt>
                    <dd class="mt-1 font-medium text-zinc-800">{{ $this->reviewing->category->name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Year') }}</dt>
                    <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $this->reviewing->year }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Encoded on') }}</dt>
                    <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $this->reviewing->created_at->format('M j, Y') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Starting date') }}</dt>
                    <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $this->reviewing->start_date?->format('M j, Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Completion date') }}</dt>
                    <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $this->reviewing->target_date?->format('M j, Y') ?? '—' }}</dd>
                </div>
                @if ($this->reviewing->terminal_uploaded_at)
                    <div class="col-span-2">
                        <dt class="text-zinc-500">{{ __('Terminal report uploaded') }}</dt>
                        <dd class="mt-1 font-medium tabular-nums text-zinc-800">
                            {{ $this->reviewing->terminal_uploaded_at->format('M j, Y') }}
                            @if (($onTime = $this->reviewing->completedOnTime()) !== null)
                                ({{ $onTime ? __('on time') : __('late') }})
                            @endif
                        </dd>
                    </div>
                @endif
                @if ($this->reviewing->designation)
                    <div>
                        <dt class="text-zinc-500">{{ __('Designation') }}</dt>
                        <dd class="mt-1 font-medium text-zinc-800">{{ $this->reviewing->designation }}</dd>
                    </div>
                @endif
                <div class="col-span-2">
                    <dt class="text-zinc-500">{{ __('Proponents') }}</dt>
                    <dd class="mt-1 grid gap-1 text-zinc-800">
                        @foreach ($this->reviewing->proponents->sortBy('study')->groupBy('study') as $study => $rows)
                            <p><span class="font-medium">{{ __('Study :number', ['number' => $study]) }}:</span> {{ $rows->map(fn ($row) => $row->user->name.' ('.__($row->role).', '.($row->user->department?->code ?? __('No college')).')')->join(', ') }}</p>
                        @endforeach
                    </dd>
                </div>
                @if ($this->reviewing->abstract)
                    <div class="col-span-2">
                        <dt class="text-zinc-500">{{ __('Abstract') }}</dt>
                        <dd class="mt-1 whitespace-pre-line text-zinc-800">{{ $this->reviewing->abstract }}</dd>
                    </div>
                @endif
            </dl>

            @if ($this->reviewing->concept_path)
                <div>
                    <flux:button size="sm" :href="route('submissions.document', [$this->reviewing, 'concept'])" target="_blank" rel="noopener" icon="document-text" icon:trailing="arrow-top-right-on-square" :variant="$this->reviewing->awaiting_review ? 'primary' : 'outline'" class="max-sm:h-11">
                        {{ __('Open concept proposal') }}
                    </flux:button>
                </div>
            @endif

            {{-- Only an upload waiting for review can be decided, and remarks only go with a return --}}
            @php($pending = $this->reviewing->conceptReview() === 'pending')
            @if ($pending)
                <flux:separator variant="subtle" />

                <flux:textarea wire:model="remarks" :label="__('What to fix')" :description="__('Needed to return it for revision. Everyone on the project sees this. Passing it saves no remarks.')" rows="4" maxlength="2000" />
            @endif

            <flux:error name="review" />

            {{-- Pass sits last, where the eye ends; on phones the two decisions stack full width above Cancel --}}
            <div class="flex flex-wrap justify-end gap-2 max-sm:flex-col-reverse">
                <flux:modal.close>
                    <flux:button variant="ghost" class="max-sm:h-11 max-sm:w-full">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                @if ($pending)
                    <flux:button wire:click="saveReview('returned')" icon="arrow-uturn-left" data-test="return-concept-button" class="max-sm:h-11 max-sm:w-full">{{ __('Return for revision') }}</flux:button>
                    <flux:button wire:click="saveReview('passed')" variant="primary" icon="check" data-test="pass-concept-button" class="max-sm:h-11 max-sm:w-full">{{ __('Pass concept') }}</flux:button>
                @endif
            </div>
        </div>
    @endif
    @endisland
</flux:modal>
