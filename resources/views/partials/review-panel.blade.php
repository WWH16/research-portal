{{-- The review flyout for pages using App\Concerns\ReviewsSubmissions --}}
@use('App\Models\Submission')
@use('Illuminate\Support\Str')
<flux:modal name="review-submission" variant="flyout" wire:close="closeReview" class="w-full max-sm:p-5 sm:w-[30rem]" aria-labelledby="review-submission-heading">
    {{-- Also redraws on full renders, which is free once a closed review forgets its project --}}
    @island(name: 'review', always: true)
    @if ($this->reviewing)
        <form wire:submit="saveReview" class="flex flex-col gap-6">
            <div class="pe-8">
                <flux:heading size="lg" id="review-submission-heading">{{ $this->reviewing->title }}</flux:heading>
                <flux:text class="mt-1">{{ __('Filed by :name · :college', ['name' => $this->reviewing->user->name, 'college' => $this->reviewing->department->code]) }}</flux:text>
            </div>

            @include('partials.project-stages', ['submission' => $this->reviewing, 'next' => false])

            @php($latest = $this->reviewing->latestUpload())
            @php($open = $this->reviewing->reviewableStatuses())
            {{-- The open statuses are always the first few in order, so the first locked one comes right after them --}}
            @php($firstLocked = Submission::STATUSES[count($open)] ?? null)
            @if ($this->reviewing->awaiting_review)
                <flux:callout color="blue" icon="document-arrow-up" data-test="review-to-check">
                    <flux:callout.heading>
                        {{ $latest ? __('To review: :document', ['document' => __(Submission::DOCUMENTS[$latest['stage']])]) : __('Waiting for review') }}
                    </flux:callout.heading>
                    @if ($latest)
                        <flux:callout.text>{{ __('Uploaded :date. Open it below, then set the status or leave remarks.', ['date' => $latest['at']->format('M j, Y, g:i A')]) }}</flux:callout.text>
                    @endif
                </flux:callout>
            @else
                <flux:text class="text-sm" data-test="review-nothing-new">{{ __('Already reviewed. No new document since then.') }}</flux:text>
            @endif

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

            <div>
                <p class="text-sm text-zinc-500">{{ __('Documents') }}</p>
                <div class="mt-2 flex flex-wrap gap-2 max-sm:grid">
                    @foreach (Submission::DOCUMENTS as $stage => $label)
                        @if ($this->reviewing->{$stage.'_path'})
                            <flux:button size="sm" :href="route('submissions.document', [$this->reviewing, $stage])" target="_blank" rel="noopener" icon="document-text" icon:trailing="arrow-top-right-on-square" :variant="$this->reviewing->awaiting_review && $latest && $latest['stage'] === $stage ? 'primary' : 'outline'" class="max-sm:h-11">
                                {{ __($label) }}
                            </flux:button>
                        @endif
                    @endforeach
                </div>
            </div>

            <flux:separator variant="subtle" />

            {{-- Four statuses don't fit one segmented row on phones, so they sit two by two there --}}
            <flux:radio.group wire:model="status" :label="__('Status')" :description="__('Pick the stage whose document you accept: Concept or Detailed for a proposal, Completed for the terminal report. Saving without a change still takes the project off the review list.')" variant="segmented" class="max-sm:grid max-sm:h-auto max-sm:grid-cols-2 max-sm:gap-1">
                @foreach (Submission::STATUSES as $option)
                    @php($locked = $loop->index >= count($open))
                    <flux:radio :value="$option" :label="__($option)" :icon="$locked ? 'lock-closed' : null" :disabled="$locked" class="max-sm:h-10" />
                @endforeach
            </flux:radio.group>

            {{-- Says why the first locked stage is locked: its document isn't in yet, or the stage before it isn't reached --}}
            @if ($firstLocked)
                <flux:text class="-mt-3 flex items-center gap-1.5 text-sm" data-test="review-locked-hint">
                    <flux:icon.lock-closed variant="micro" class="shrink-0" />
                    {{ count($open) === $this->reviewing->stageIndex() + 1
                        ? __(':stage opens once the :document is uploaded.', ['stage' => __($firstLocked), 'document' => Str::lower(__(Submission::DOCUMENTS[Submission::STAGE_DOCUMENTS[$firstLocked]]))])
                        : __(':stage opens after the project reaches :previous.', ['stage' => __($firstLocked), 'previous' => __(Submission::STATUSES[count($open) - 1])]) }}
                </flux:text>
            @endif

            @if ($this->reviewing->status === 'Completed')
                <flux:checkbox wire:model="reopenUploads" :label="__('Reopen for a corrected upload')" :description="__('Lets the proponents upload one more document while the project stays Completed. Uploads close again once it comes in.')" data-test="reopen-uploads" />
            @endif

            <flux:textarea wire:model="remarks" :label="__('Remarks')" :description="__('Say what to fix when a document is incomplete. Everyone on the project sees these.')" rows="4" maxlength="2000" />

            <flux:error name="review" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" class="max-sm:h-11">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-review-button" class="max-sm:h-11">{{ __('Save review') }}</flux:button>
            </div>
        </form>
    @endif
    @endisland
</flux:modal>
