@props(['sealFrom' => null, 'preheader' => null])
<x-mail::layout>
{{-- Banner: the seal beside the portal's and the university's names, on the green band across the card --}}
<x-slot:header>
<x-mail::header :seal-from="$sealFrom" :preheader="$preheader">
<p class="masthead-name">{{ __('Faculty Research Portal') }}</p>
<p class="masthead-institution">{{ __('Isabela State University - Cauayan Campus') }}</p>
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Footer: who sent it, where they are, why this address got it, and the way back to the portal --}}
<x-slot:footer>
<x-mail::footer>
{{ __('Faculty Research Portal · Isabela State University - Cauayan Campus') }}<br>
{{ __('18 Dacanay, Brgy. San Fermin, Cauayan City, Isabela') }}

{{ __('You received this email because this address was used on the Faculty Research Portal.') }}

[{{ parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url') }}]({{ config('app.url') }}) · © {{ now()->year }} {{ __('Isabela State University') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
