@props([
    'url',
    'color' => 'primary',
    'align' => 'left',
])
@php
    // Outlook on Windows ignores padding and radius on links, so it draws the button in VML at a fixed width.
    // ponytail: width guessed from the label length; fine for short labels like "Verify email".
    $vmlWidth = max(120, (int) ceil(mb_strlen(trim(strip_tags($slot))) * 8 + 34));
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:40px;v-text-anchor:middle;width:{{ $vmlWidth }}px;" arcsize="20%" strokecolor="#0a5531" fillcolor="#0b5e36">
<w:anchorlock/>
<center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:500;">{!! $slot !!}</center>
</v:roundrect>
<![endif]-->
<!--[if !mso]><!-->
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener">{!! $slot !!}</a>
<!--<![endif]-->
</td>
</tr>
</table>
</td>
</tr>
</table>
