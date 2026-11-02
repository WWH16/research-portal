<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
:root {
color-scheme: light dark;
supported-color-schemes: light dark;
}

/* Gmail and Apple Mail turn a bare address (the reader's own, in the first line) into a blue link. Keep it plain
   text. The portal's own links carry an inline color and underline, so these rules leave them as they are. */
u + .body a {
color: inherit;
text-decoration: none;
}

a[x-apple-data-detectors] {
color: inherit !important;
font: inherit !important;
text-decoration: none !important;
}

/* Dark mode (Apple Mail, Outlook for Mac and iOS): light text on dark, links stay blue and underlined. */
@media (prefers-color-scheme: dark) {
body {
background-color: #121212 !important;
}

.sheet p,
.sheet h1,
.sheet li {
color: #e8e8e8 !important;
}

.sheet .footer-cell p {
color: #a8a8a8 !important;
}

.sheet a {
color: #8ab4f8 !important;
}

.sheet .footer-cell {
border-color: #3a3a3a !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
{{-- The class lets the autolink rule above find the body inside Gmail --}}
<body class="body">

{{-- A plain letter: one left-aligned column of text on white, no banner, card or button --}}
<table class="sheet" align="left" width="600" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</body>
</html>
