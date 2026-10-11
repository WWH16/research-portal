<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
{{-- Outlook on Windows can't load web fonts and falls back to Times, so it gets Arial instead --}}
<!--[if mso]>
<style>
body, table, td, p, a, h1 { font-family: Arial, Helvetica, sans-serif !important; }
</style>
<![endif]-->
<!--[if !mso]><!-->
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&display=swap" rel="stylesheet">
<!--<![endif]-->
<style>
:root {
color-scheme: light dark;
supported-color-schemes: light dark;
}

/* Gmail and Apple Mail turn a bare address (the reader's own, in the first line) into a blue link. Keep it plain
   text. The portal's own links carry an inline color and underline, so these rules leave them green. */
u + .body a {
color: inherit;
text-decoration: none;
}

a[x-apple-data-detectors] {
color: inherit !important;
font: inherit !important;
text-decoration: none !important;
}

/* Like the portal's primary button, it deepens on hover instead of fading */
.sheet .content-cell a.button-primary:hover {
background-color: #094b2b !important;
}

@media only screen and (max-width: 640px) {
.sheet,
.footer {
width: 100% !important;
}

.masthead {
padding: 20px 24px !important;
}

.content-cell {
padding-left: 24px !important;
padding-right: 24px !important;
}

.sheet .content-cell {
padding-top: 28px !important;
padding-bottom: 28px !important;
}

.sheet .content-cell h1 {
font-size: 20px !important;
}
}

@media only screen and (max-width: 500px) {
.button {
display: block !important;
}
}

/* Dark mode (Apple Mail, Outlook for Mac and iOS). The banner and button keep the seal green. */
@media (prefers-color-scheme: dark) {
body,
.wrapper {
background-color: #0f1412 !important;
}

.sheet {
background-color: #171d1a !important;
border-color: #2c3531 !important;
}

.sheet .content-cell h1 {
color: #f2f5f3 !important;
}

.sheet .content-cell p,
.sheet .content-cell li {
color: #dce2df !important;
}

.footer .content-cell {
border-color: #2c3531 !important;
}

.sheet .content-cell .panel-item p,
.footer p {
color: #a3ada8 !important;
}

.sheet .content-cell a,
.footer a {
color: #86d1a8 !important;
}

.sheet .content-cell a.button-primary {
border-color: #0b5e36 !important;
color: #ffffff !important;
}
}

/* Outlook.com and the new Outlook mark what they recolor in dark mode; keep the banner and button green */
[data-ogsb] .masthead,
[data-ogsb] .button-primary {
background-color: #0b5e36 !important;
}

[data-ogsc] .button-primary,
[data-ogsc] .masthead-name,
[data-ogsc] .masthead-institution {
color: #ffffff !important;
}
</style>
{!! $head ?? '' !!}
</head>
{{-- The class lets the autolink rule above find the body inside Gmail --}}
<body class="body">

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">

{{-- The sheet: one white card with the green banner across its top --}}
<tr>
<td class="sheet-row">
<table class="sheet" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
