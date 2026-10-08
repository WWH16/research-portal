<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->logAuthEvents();

        // Fortify fires Verified when the emailed link verifies someone; the app layout shows this as a toast.
        Event::listen(Verified::class, fn () => session()->flash('verified', __('Email verified. Welcome to the portal.')));

        $this->configureEmails();
    }

    /**
     * Record sign-ins and account security changes in the activity log. Each event names its user, since
     * a sign-in or reset has no one signed in yet. Laravel's Login also fires when a remember-me cookie
     * signs someone back in after their session ends.
     */
    private function logAuthEvents(): void
    {
        // The events type their user as the auth contract; skipping anything but a User also covers a
        // logout from a session that already expired, which has no one to attribute.
        $log = fn (string $action, mixed $user) => $user instanceof User && ActivityLog::record($action, user: $user);

        Event::listen(Login::class, fn (Login $event) => $log('auth.login', $event->user));
        Event::listen(Logout::class, fn (Logout $event) => $log('auth.logout', $event->user));
        Event::listen(Registered::class, fn (Registered $event) => $log('auth.registered', $event->user));
        Event::listen(Verified::class, fn (Verified $event) => $log('auth.verified', $event->user));
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => $log('auth.password_reset', $event->user));
        Event::listen(TwoFactorAuthenticationConfirmed::class, fn ($event) => $log('auth.two_factor_enabled', $event->user));
        Event::listen(TwoFactorAuthenticationDisabled::class, fn ($event) => $log('auth.two_factor_disabled', $event->user));
        Event::listen(RecoveryCodesGenerated::class, fn ($event) => $log('auth.recovery_codes', $event->user));
    }

    /**
     * Word the verification and password reset emails for the portal instead of Laravel's generic copy.
     */
    private function configureEmails(): void
    {
        // The body is parsed as Markdown, so an address like first_last@isu.edu.ph must not turn into italics.
        $md = fn (string $text) => addcslashes($text, '\\`*_[]<>');

        $expires = fn (int $minutes) => __('Link expires in :count minutes.', ['count' => $minutes]);

        VerifyEmail::toMailUsing(fn ($user, string $url) => (new MailMessage)
            ->subject(__('Verify your email for the Research Portal'))
            ->greeting(__('Verify your email'))
            ->line(__('Confirm :email to activate your Research Portal account.', ['email' => $md($user->email)]))
            ->action(__('Verify email'), $url)
            ->line($expires(config('auth.verification.expire', 60)))
            // A Markdown mailto link to the sender, so it reaches the Research Office and reads as a link in plain text.
            ->line(__('Didn’t sign up?').' ['.__('Contact the Research Office').'](mailto:'.config('mail.from.address').').')
            ->markdown('notifications::email', ['icon' => 'envelope']));

        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject(__('Reset your Research Portal password'))
            ->greeting(__('Reset your password'))
            ->line(__('Set a new password for :email.', ['email' => $md($user->getEmailForPasswordReset())]))
            ->action(__('Reset password'), url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false)))
            ->line($expires(config('auth.passwords.'.config('auth.defaults.passwords').'.expire')))
            ->line(__('Didn’t ask for this? Ignore this email. Your password stays the same.'))
            ->markdown('notifications::email', ['icon' => 'lock-closed']));
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::registerView(fn () => view('pages::auth.register', [
            'departments' => Department::orderBy('code')->get(['id', 'code', 'name']),
        ]));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
