@props(['sealFrom' => null, 'preheader' => null])
<x-mail::layout>
{{-- Masthead: the seal over the university's name, centered at the top of the sheet --}}
<x-slot:header>
<x-mail::header :seal-from="$sealFrom" :preheader="$preheader">
<p class="masthead-institution">{{ __('Isabela State University - Cauayan Campus') }}</p>
<p class="masthead-name">{{ __('Faculty Research Portal') }}</p>
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

{{-- Footer: who sent it, where they are, and why this address got it --}}
<x-slot:footer>
<x-mail::footer>
{{ __('Faculty Research Portal · Isabela State University - Cauayan Campus') }}<br>
{{ __('18 Dacanay, Brgy. San Fermin, Cauayan City, Isabela') }}

{{ __('You received this email because this address was used on the Faculty Research Portal.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
