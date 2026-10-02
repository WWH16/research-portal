<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Backup and Restore')] class extends Component {
    /**
     * Laravel Cloud replaces the submissions and public disks with its buckets when they are attached
     * under those disk names, so an s3 driver here means uploads survive a deploy.
     */
    public function inBucket(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 's3';
    }
}; ?>

<section class="mx-auto w-full max-w-5xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Backup and Restore') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Laravel Cloud keeps the portal\'s backups. Restores are done from the Laravel Cloud dashboard.') }}</flux:text>
        </div>
    </header>

    @if (app()->isProduction() && ! ($this->inBucket('submissions') && $this->inBucket('public')))
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" data-test="files-on-server">
            <flux:callout.heading>{{ __('Uploaded files are on the server disk') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Laravel Cloud clears this disk on every deploy. Attach a private bucket named "submissions" and a public bucket named "public" to the environment, then redeploy.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="divide-y divide-line">
        <div class="grid gap-4 py-8 sm:grid-cols-[14rem_1fr]">
            <div>
                <flux:heading size="lg" level="2">{{ __('Database') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Projects, users, reviews, filing options and the activity log.') }}</flux:text>
            </div>
            <div class="max-w-prose space-y-4">
                <flux:text>{{ __('Laravel Cloud takes a backup of the database every day between 3 AM and 6 AM EDT and keeps it for 1 to 30 days. You can also take a backup by hand before a big change.') }}</flux:text>

                <div>
                    <flux:heading level="3">{{ __('To restore a backup') }}</flux:heading>
                    <ol class="mt-2 list-decimal space-y-1 ps-5 text-sm text-zinc-500 dark:text-white/70">
                        <li>{{ __('In Laravel Cloud, open Organization, then Resources, then Databases.') }}</li>
                        <li>{{ __('Open the portal\'s database cluster, then Backups.') }}</li>
                        <li>{{ __('Choose Restore backup and pick the date. Cloud restores it into a new cluster and leaves the current data as it is.') }}</li>
                        <li>{{ __('Attach the restored database to the environment and redeploy.') }}</li>
                    </ol>
                </div>

                <flux:button href="https://cloud.laravel.com" target="_blank" icon-trailing="arrow-top-right-on-square">
                    {{ __('Open Laravel Cloud') }}
                </flux:button>
            </div>
        </div>

        <div class="grid gap-4 py-8 sm:grid-cols-[14rem_1fr]">
            <div>
                <flux:heading size="lg" level="2">{{ __('Uploaded files') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Project documents and profile photos.') }}</flux:text>
            </div>
            <div class="max-w-prose space-y-4">
                <flux:text>{{ __('Files are stored in Laravel Cloud object storage, apart from the server, so a deploy or restart never removes them. Database backups don\'t include them, and object storage keeps no backups of its own: a document deleted or replaced in the portal can\'t be brought back.') }}</flux:text>

                <div class="flex flex-wrap gap-2">
                    @foreach (['submissions' => __('Project documents'), 'public' => __('Profile photos')] as $disk => $label)
                        <flux:badge size="sm" :color="$this->inBucket($disk) ? 'green' : 'zinc'">
                            {{ $label }}: {{ $this->inBucket($disk) ? __('Cloud bucket') : __('Server disk') }}
                        </flux:badge>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="grid gap-4 py-8 sm:grid-cols-[14rem_1fr]">
            <div>
                <flux:heading size="lg" level="2">{{ __('Setting up Laravel Cloud') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Do this once, before the first deploy.') }}</flux:text>
            </div>
            <div class="max-w-prose space-y-4">
                <ol class="list-decimal space-y-1 ps-5 text-sm text-zinc-500 dark:text-white/70">
                    <li>{{ __('Attach a private bucket to the environment with the disk name "submissions". It holds project documents.') }}</li>
                    <li>{{ __('Attach a public bucket with the disk name "public". It holds profile photos.') }}</li>
                    <li>{{ __('Open the database cluster\'s Backups page and choose Daily. Backups are kept for 7 days unless you change it.') }}</li>
                    <li>{{ __('Redeploy the environment.') }}</li>
                </ol>

                <flux:text>{{ __('Files uploaded before the move stay on the old server. Copy them into the buckets with a tool such as Cyberduck, keeping the same folders.') }}</flux:text>
            </div>
        </div>
    </div>
</section>
