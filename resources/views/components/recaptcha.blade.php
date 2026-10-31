@props(['action'])
{{-- reCAPTCHA v3, invisible: on submit it asks Google for a token, puts it in the form, then sends the form. It ends
     with Google's required notice as inline text, so a form sets it in its own fine print. Renders nothing while
     the site key is empty. --}}
@if ($key = config('services.recaptcha.site_key'))
    <input type="hidden" name="recaptcha_token">
    <script src="https://www.google.com/recaptcha/api.js?render={{ $key }}" async defer></script>
    <script>
        (() => {
            const form = document.currentScript.closest('form');

            form.addEventListener('submit', (event) => {
                if (form.recaptcha_token.value) return;

                event.preventDefault();
                grecaptcha.ready(() => grecaptcha.execute(@js($key), { action: @js($action) }).then((token) => {
                    form.recaptcha_token.value = token;
                    form.requestSubmit();
                }));
            });
        })();
    </script>
    {{ __('This site is protected by') }} <span translate="no">reCAPTCHA</span> {{ __('and the') }}
    <flux:link href="https://policies.google.com/privacy">{{ __('Google Privacy Policy') }}</flux:link>
    {{ __('and') }}
    <flux:link href="https://policies.google.com/terms">{{ __('Google Terms of Service') }}</flux:link>
    {{ __('apply.') }}
@endif
