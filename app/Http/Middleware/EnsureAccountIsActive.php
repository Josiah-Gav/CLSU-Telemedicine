<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of anyone whose account is no longer active.
 *
 * Account status used to be checked in exactly one place —
 * LoginRequest::authenticate() — which answers "may this person sign in?" and
 * nothing else. Suspending an account therefore did not take effect until the
 * person's session expired on its own, up to SESSION_LIFETIME minutes of
 * inactivity later. For a clinical system revocation has to be immediate: a
 * suspended nurse or physician otherwise keeps reading patient records for the
 * rest of their session.
 *
 * Registered on the web group so the check is enforced in one place rather than
 * repeated across controllers. It deliberately asserts nothing about roles or
 * permissions — it only answers "is this account still allowed to be signed
 * in?" — so it composes with, and does not duplicate, the existing role guards
 * and policies.
 *
 * Guests pass straight through: the check is skipped entirely unless someone is
 * authenticated, so login, registration, password reset and staff activation
 * are untouched. That is also what makes a redirect loop impossible — by the
 * time the browser follows the redirect to the login page, the request is a
 * guest request and this middleware does nothing.
 *
 * Ordered ahead of TrackUserPresence in bootstrap/app.php so a suspended
 * account's presence is not refreshed on the way out; otherwise a suspended
 * physician could still appear online and keep consultation intake open.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->account_status !== 'active') {
            Auth::guard('web')->logout();

            // invalidate() drops the session data, regenerateToken() reissues
            // the CSRF token — the same pair AuthenticatedSessionController's
            // own logout performs, so a revoked session is torn down exactly
            // as a deliberate one is.
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Your account is no longer active. Please contact the infirmary administrator.';

            // The application polls several JSON endpoints every few seconds.
            // Answering those with a redirect to an HTML login page would have
            // the client parse markup as JSON; 401 is what its fetch handlers
            // can act on.
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
