{{-- Hands the mail message down to the HTML header, which embeds the seal; null in previews and test renders.
     The first intro line, without its Markdown escapes, doubles as the inbox preview text. --}}
<x-mail::message :seal-from="$message ?? null" :preheader="isset($introLines[0]) ? stripslashes($introLines[0]) : null">
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines: the fine print under the button (expiry, what to do if it wasn't you) --}}
@if (count($outroLines) > 0)
<x-mail::panel>
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
</x-mail::panel>
@endif
</x-mail::message>
