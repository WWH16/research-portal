{{-- The concept proposal review flyout for pages using App\Concerns\ReviewsSubmissions --}}
<flux:modal name="review-submission" variant="flyout" wire:close="closeReview" class="w-full max-sm:p-5 sm:w-[30rem]" aria-labelledby="review-submission-heading">
    {{-- Also redraws on full renders, which is free once a closed review forgets its project --}}
    @island(name: 'review', always: true)
    @if ($this->reviewing)
        {{-- Not a form: Enter must never pass a concept proposal by accident, so each decision is its own button --}}
        {{-- Block @php only in this file: Blade reads an inline @php() up to the next @endphp --}}
        @php
            $review = $this->reviewing->conceptReview();
            $uploaded = $this->reviewing->uploadTimes()['concept'] ?? null;
            [$color, $icon, $heading, $text] = match ($review) {
                'pending' => ['zinc', 'document-arrow-up', null, ($uploaded ? __('Uploaded :date.', ['date' => $uploaded->format('M j, Y, g:i A')]).' ' : '').__('Open it, then pass it or return it for revision. The detailed proposal stays locked until it passes.')],
                'passed' => ['green', 'check-circle', __('Concept proposal passed'), __('The proponents can upload the detailed proposal. A replaced concept proposal stays passed.')],
                default => ['amber', 'arrow-uturn-left', __('Returned for revision'), __('Waiting for the proponents to upload a corrected concept proposal.')],
            };
        @endphp
        <div class="flex flex-col gap-6">
            <div class="pe-8">
                <flux:heading size="lg" id="review-submission-heading">{{ $this->reviewing->title }}</flux:heading>
                <flux:text class="mt-1">{{ __('Filed by :name · :college', ['name' => $this->reviewing->user->displayName(), 'college' => $this->reviewing->department->code]) }}</flux:text>
            </div>

            {{-- Left out while a concept proposal waits: the project can only be at Concept then, with every later stage locked --}}
            @if ($review !== 'pending')
                @include('partials.project-stages', ['submission' => $this->reviewing, 'next' => false])
            @endif

            {{-- A waiting review reads neutral, apart from the green of a pass and the amber of a return. The document opens
                 from here, beside the line that says to open it, and leaves the panel's one primary button to the decision. --}}
            <flux:callout :color="$color" :icon="$icon" :heading="$heading" :text="$text" :data-test="$review === 'pending' ? 'review-to-check' : 'review-nothing-new'">
                @if ($this->reviewing->concept_path)
                    <x-slot name="actions">
                        <flux:button size="sm" variant="outline" :href="route('submissions.document', [$this->reviewing, 'concept'])" target="_blank" rel="noopener" icon="document-text" icon:trailing="arrow-top-right-on-square" class="max-sm:h-11">
                            {{ __('Open concept proposal') }}
                        </flux:button>
                    </x-slot>
                @endif
            </flux:callout>

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
                            <p><span class="font-medium">{{ __('Study :number', ['number' => $study]) }}:</span> {{ $rows->map(fn ($row) => $row->user->displayName().' ('.__($row->role).', '.($row->user->department?->code ?? __('No college')).')')->join(', ') }}</p>
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

            {{--
                Only an upload waiting for review can be decided. Nothing is picked at first, so a pass is always a
                choice, never the nearest button. The remarks show for a return, or while they hold text, so a pass
                refused for carrying remarks shows its error under them. Keyed by project, so each review starts unpicked.
            --}}
            @if ($review === 'pending')
                <flux:separator variant="subtle" />

                <div x-data="{ decision: '' }" wire:key="decision-{{ $this->reviewing->id }}" class="grid gap-4">
                    <flux:radio.group x-model="decision" variant="segmented" :label="__('Your decision')" data-test="review-decision" class="*:transition-colors *:not-data-checked:hover:bg-white/60 dark:*:not-data-checked:hover:bg-white/10">
                        <flux:radio value="passed" icon="check" :label="__('Pass')" />
                        <flux:radio value="returned" icon="arrow-uturn-left" :label="__('Return for revision')" />
                    </flux:radio.group>

                    <div x-show="decision === 'returned' || $wire.remarks.trim()" x-cloak>
                        <flux:textarea wire:model="remarks" :label="__('What to fix')" :description="__('Everyone on the project sees this.')" rows="4" maxlength="2000" />
                    </div>

                    <flux:text x-show="decision === 'passed'" x-cloak>{{ __('The proponents can then upload the detailed proposal. A pass can\'t be undone.') }}</flux:text>

                    <flux:button wire:click="saveReview(decision)" x-bind:disabled="! decision" variant="primary" data-test="save-review-button" class="w-full max-sm:h-11">
                        <span x-text="{ passed: @js(__('Pass concept')), returned: @js(__('Return for revision')) }[decision] ?? @js(__('Pick a decision'))">{{ __('Pick a decision') }}</span>
                    </flux:button>
                </div>
            @endif

            <flux:error name="review" />

            <flux:modal.close>
                <flux:button variant="ghost" class="w-full max-sm:h-11">{{ $review === 'pending' ? __('Cancel') : __('Close') }}</flux:button>
            </flux:modal.close>
        </div>
    @endif
    @endisland
</flux:modal>
