@props(['sealFrom' => null, 'preheader' => null])
@php
    // Sent mail carries the seal inside it (cid:), so it shows even before the portal has a public address.
    // Only the HTML copy has this header, so the seal is attached once. Previews link the public file instead.
    $seal = $sealFrom ? $sealFrom->embed(public_path('images/isu_seal-128.png')) : asset('images/isu_seal-128.png');
@endphp
<tr>
<td class="masthead" align="center">
@if ($preheader)
{{-- Inbox preview text. The trailing invisible characters keep the masthead and headline out of the preview. --}}
<div style="display: none; max-height: 0; overflow: hidden;">{{ $preheader }}{!! str_repeat('&#847;&zwnj;&nbsp;', 80) !!}</div>
@endif
{{-- Decorative: the university's name is set right under it --}}
<img src="{{ $seal }}" class="seal" width="56" height="56" alt="">
{!! $slot !!}
</td>
</tr>
