<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ __('Research Portal') }}, {{ __('Isabela State University') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ __('Research Portal · Isabela State University, Cauayan Campus') }}
            {{ __('18 Dacanay, Brgy. San Fermin, Cauayan City, Isabela') }}

            {{ __('You received this email because this address was used on the Research Portal.') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
