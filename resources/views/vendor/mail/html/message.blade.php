@props(['preheader' => null])
<x-mail::layout>
<x-slot:header>
<x-mail::header :preheader="$preheader" />
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Sign-off: who the letter is from --}}
{{ __('Thank you,') }}

**{{ __('Faculty Research Portal') }}**<br>
{{ __('Isabela State University – Cauayan Campus') }}

{{-- Footer: why this address got it, the way back to the portal, and where the campus is --}}
<x-slot:footer>
<x-mail::footer>
{{ __('You received this email because this address was used on the Faculty Research Portal.') }}

{{ __('Visit the portal') }}<br>
[{{ config('app.url') }}]({{ config('app.url') }})

{{ __('18 Dacanay, Brgy. San Fermin, Cauayan City, Isabela') }}<br>
© {{ now()->year }} {{ __('Isabela State University') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
