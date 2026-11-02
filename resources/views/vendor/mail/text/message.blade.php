<x-mail::layout>
    {{-- Body --}}
    {{ $slot }}

    {{-- Sign-off and footer, as in the HTML letter --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ __('Thank you,') }}

            {{ __('Faculty Research Portal') }}
            {{ __('Isabela State University – Cauayan Campus') }}

            ---
            {{ __('You received this email because this address was used on the Faculty Research Portal.') }}

            {{ __('Visit the portal') }}: {{ config('app.url') }}

            {{ __('18 Dacanay, Brgy. San Fermin, Cauayan City, Isabela') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
