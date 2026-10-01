<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\Department;
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

        // Fortify fires Verified when the emailed link verifies someone; the app layout shows this as a toast.
        Event::listen(Verified::class, fn () => session()->flash('verified', __('Email verified. Welcome to the portal.')));

        $this->configureEmails();
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
