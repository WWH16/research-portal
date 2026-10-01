<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
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
use Illuminate\Support\HtmlString;
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

        // Tied to the account when the email matches one, so that member's activity shows attempts on it.
        // Whether it matched is saved too, since a later account deletion clears the user.
        // People sometimes type their password into the email box, so anything that isn't an email is never saved.
        // Emails are saved lowercase, so searching the log finds them whatever case was typed.
        Event::listen(Failed::class, function (Failed $event) {
            $typed = (string) ($event->credentials[Fortify::username()] ?? '');
            $email = filter_var($typed, FILTER_VALIDATE_EMAIL) ? Str::lower($typed) : null;

            ActivityLog::record('auth.failed', properties: array_filter([
                'email' => $email,
                'not_email' => $email === null,
                'no_account' => ! $event->user instanceof User,
            ]), user: $event->user instanceof User ? $event->user : null);
        });
    }

    /**
     * Word the verification and password reset emails for the portal instead of Laravel's generic copy.
     */
    private function configureEmails(): void
    {
        // Two lines like Laravel's own "Regards," sign-off; built per email so it's translated at send time.
        $signOff = fn () => new HtmlString(e(__('Regards,')).'<br>'.e(__('Research Office, Isabela State University')));

        VerifyEmail::toMailUsing(fn ($user, string $url) => (new MailMessage)
            ->subject(__('Verify your email for the Research Portal'))
            ->greeting(__('Hello, :name', ['name' => $user->name]))
            ->line(__('Confirm this is your email address to finish setting up your Research Portal account.'))
            ->action(__('Verify email address'), $url)
            ->line(__('This link expires in :count minutes.', ['count' => config('auth.verification.expire', 60)]))
            ->line(__('If you didn’t create an account, you can ignore this email.'))
            ->salutation($signOff()));

        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject(__('Reset your Research Portal password'))
            ->greeting(__('Hello, :name', ['name' => $user->name]))
            ->line(__('We got a request to reset the password for your Research Portal account.'))
            ->action(__('Reset password'), url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false)))
            ->line(__('This link expires in :count minutes.', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('If you didn’t ask for this, you can ignore this email. Your password stays the same.'))
            ->salutation($signOff()));
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
