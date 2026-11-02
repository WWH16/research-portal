@props(['preheader' => null])
@if ($preheader)
<tr>
<td>
{{-- Inbox preview text. The trailing invisible characters keep the headline out of the preview. --}}
<div style="display: none; max-height: 0; overflow: hidden;">{{ $preheader }}{!! str_repeat('&#847;&zwnj;&nbsp;', 80) !!}</div>
</td>
</tr>
@endif
