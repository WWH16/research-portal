@props(['url', 'sealFrom' => null])
@php
    // Sent mail carries the seal inside it (cid:), so it shows even before the portal has a public address.
    // Only the HTML copy has this header, so the seal is attached once. Previews link the public file instead.
    $seal = $sealFrom ? $sealFrom->embed(public_path('images/isu_seal.png')) : asset('images/isu_seal.png');
@endphp
<tr>
<td class="header">
<table class="brand-bar" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-seal" width="68" valign="middle">
<a href="{{ $url }}" style="display: inline-block;"><img src="{{ $seal }}" class="seal" width="40" height="40" alt=""></a>
</td>
<td class="brand-name" valign="middle">
<a href="{{ $url }}" style="display: inline-block;">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
