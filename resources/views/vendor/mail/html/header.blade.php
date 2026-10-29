@props(['preheader' => null])
<tr>
<td class="masthead" bgcolor="#0b5e36" align="left">
@if ($preheader)
{{-- Inbox preview text. The trailing invisible characters keep the masthead and headline out of the preview. --}}
<div style="display: none; max-height: 0; overflow: hidden;">{{ $preheader }}{!! str_repeat('&#847;&zwnj;&nbsp;', 80) !!}</div>
@endif
{{-- The seal beside the university's and the portal's names --}}
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="seal-cell" width="52" valign="middle">
{{-- Decorative: the university's name is set right beside it. Linked from APP_URL, not embedded, so no attachment. --}}
<img src="{{ asset('images/isu_seal-128.png') }}" class="seal" width="52" height="52" alt="">
</td>
<td valign="middle">
{!! $slot !!}
</td>
</tr>
</table>
</td>
</tr>
