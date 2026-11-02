{{-- The first intro line, without its bold marks and Markdown escapes, doubles as the inbox preview text. --}}
<x-mail::message :preheader="isset($introLines[0]) ? stripslashes(str_replace('**', '', $introLines[0])) : null">
{{-- Headline: what the email is about, its own greeting or else its subject, in place of a bare "Hello!" --}}
# {{ $greeting ?: $subject }}

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines: what follows the link (expiry, what to do if it wasn't you), as plain paragraphs --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
</x-mail::message>
