@props(['sealFrom' => null])
<x-mail::layout>
{{-- On phones the brand bar narrows with the card below it --}}
<x-slot:head>
<style>
@media only screen and (max-width: 600px) {
.brand-bar {
width: 100% !important;
}
}
</style>
</x-slot:head>

{{-- Header: the portal sidebar's lockup on its seal green, joined to the card below --}}
<x-slot:header>
<x-mail::header :url="config('app.url')" :seal-from="$sealFrom">
{{ __('Research Portal') }}<br><span class="brand-subtitle">{{ __('Isabela State University') }}</span>
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ __('Isabela State University') }} · {{ __('Research Portal') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
