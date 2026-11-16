<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Concerns\ClearsOtherGuards;
use App\Http\Controllers\Controller;
use App\Support\SignedOtpLink;
use App\Support\SignedRegistrationResubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ClearsOtherGuards;

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('seller')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $seller = Auth::guard('seller')->user();

        $pending = $seller->status === 'pending' && $seller->email_verified_at !== null;
        if ($seller->status !== 'approved' && ! $pending) {
            Auth::guard('seller')->logout();

            $request->session()->flash('auth.status_message', match ($seller->status) {
                'unverified' => 'Your email has not been verified yet. Please check your email for the verification code.',
                'pending' => 'Your account is still pending administrator approval.',
                'rejected' => 'Your registration has been rejected. Please contact support or submit a new registration.'.(filled($seller->rejection_reason) ? ' Reason: '.$seller->rejection_reason : ''),
                default => 'Your account is not active.',
            });

            if ($seller->status === 'unverified') {
                $request->session()->flash('auth.resume_url', SignedOtpLink::showUrl('seller', $seller));
                $request->session()->flash('auth.resume_label', 'Verify your email now');
            }
            if ($seller->status === 'rejected' && $seller->email_verified_at !== null) {
                $request->session()->flash('auth.resume_url', SignedRegistrationResubmission::url($seller));
                $request->session()->flash('auth.resume_label', 'Edit and resubmit application');
            }

            return back();
        }

        $request->session()->regenerate();

        // One account per browser: end any other role's session.
        $this->clearOtherGuards('seller');

        if ($pending) {
            $request->session()->forget('url.intended');

            return redirect()->route('seller.dashboard');
        }

        return redirect()->intended(route('seller.dashboard'));
    }

    /**
     * Legacy seller-only logout.
     *
     * The seller sidebar now posts to /logout (unified.logout), which clears
     * every guard. This handler stays registered so old bookmarks keep
     * working, but it must never send the user back to a login screen —
     * logout always lands on the public home page.
     */
    public function logout(Request $request)
    {
        $this->clearAllGuards();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
