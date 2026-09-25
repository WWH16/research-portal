@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-1">
    <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
