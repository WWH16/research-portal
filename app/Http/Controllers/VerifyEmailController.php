<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where the emailed verification link lands, in place of Fortify's. The signed link alone proves the address is the
 * member's, so it works signed out and on another device, such as a phone, as most sites' links do. It verifies the
 * address only; it never signs anyone in, so a forwarded email can't open the account.
 */
class VerifyEmailController
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $signedIn = $request->user();
        // The signed-in member themself when the link is theirs, so what is verified here is what the rest of the request sees.
        $user = $signedIn && (string) $signedIn->getKey() === $id ? $signedIn : User::find($id);

        // Expired or altered: nothing is verified, and the member is pointed to where a new link is sent.
        if (! $request->hasValidSignature() || ! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return match (true) {
                ! $signedIn => redirect()->route('login')->with('status', __('That verification link has expired or is no longer valid. Sign in to request a new link.')),
                ! $signedIn->hasVerifiedEmail() => redirect()->route('verification.notice')->with('status', 'verification-link-expired'),
                default => redirect()->route('dashboard')->with('verified', __('Your email address is already verified.')),
            };
        }

        // The Verified listener words the confirmation; a link opened a second time verifies nothing and says so.
        if ($user->hasVerifiedEmail()) {
            $request->session()->flash('verified', __('Your email address is already verified.'));
        } elseif ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return match (true) {
            ! $signedIn => redirect()->route('login')->with('status', __('Your email address has been verified. Sign in to continue.')),
            // Signed in as someone else, as on a shared office computer: the link's address is verified all the same.
            ! $signedIn->is($user) => redirect()->route('dashboard')->with('verified', __('The email address :email has been verified. To use that account, sign out and sign in with it.', ['email' => $user->email])),
            default => redirect()->intended(route('dashboard')),
        };
    }
}
