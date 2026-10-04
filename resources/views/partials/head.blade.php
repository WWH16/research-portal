<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@php($pageTitle = filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel'))
<title>
    {{ $pageTitle }}
</title>

{{-- A shared link (Messenger, Facebook, X, Slack) previews with the university seal. Every portal page needs a
     sign-in, so link crawlers land on the sign-in page and read these there. The image URL comes from APP_URL. --}}
<meta name="description" content="{{ __('Research proposals, reviews, and records in one place.') }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ __('Research proposals, reviews, and records in one place.') }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ asset('images/share-preview.jpg') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ __('Isabela State University seal') }}">
<meta name="twitter:card" content="summary_large_image">

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.png" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
