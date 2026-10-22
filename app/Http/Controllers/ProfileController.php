<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Record the patient's consent to share records with CHIS. Conditional
     * update, so giving it again never moves the original timestamp.
     */
    public function giveChisConsent(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'patient', 403);

        User::whereKey($request->user()->getKey())
            ->whereNull('chis_consent_at')
            ->update(['chis_consent_at' => now()]);

        return Redirect::route('profile.edit')->with('status', 'chis-consent-given');
    }

    /**
     * Withdraw consent: stops future sharing. Summaries already sent stay in CHIS.
     */
    public function withdrawChisConsent(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'patient', 403);

        User::whereKey($request->user()->getKey())->update(['chis_consent_at' => null]);

        return Redirect::route('profile.edit')->with('status', 'chis-consent-withdrawn');
    }
}
