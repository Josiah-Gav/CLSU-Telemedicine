<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\PhysicianAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly PhysicianAvailabilityService $availabilityService) {}

    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $userId = (int) Auth::id();
        $user = Auth::user();

        // A physician who deliberately logs out must not keep appearing to
        // accept new consultation requests. Done before the logout below so a
        // failure here leaves the session intact and retryable rather than
        // stranding a logged-out user on an error page.
        //
        // Only intake availability changes. close() is a no-op returning null
        // for a physician with nothing open, it never touches a consultation,
        // pending request, schedule slot, or presence field, and it is reached
        // only for physicians — nurses, patients, and admins log out exactly as
        // they did before. An active consultation stays active; this feature
        // introduces no consultation timeout.
        if ($user && $user->role === 'physician') {
            $this->availabilityService->close($user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if ($userId > 0) {
            DB::table('users')
                ->where('user_id', $userId)
                ->update([
                    'online_status' => 'offline',
                    'last_seen_at' => now(),
                ]);
        }

        return redirect('/');
    }
}
