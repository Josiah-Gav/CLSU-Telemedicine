<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ClsuId;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'clsu_id' => ['required', ...ClsuId::rules()],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:150',
                'unique:'.User::class,
                // Regex to accept either clsu.edu.ph OR clsu2.edu.ph
                'regex:/^[a-zA-Z0-9._%+-]+@clsu2?\.edu\.ph$/i',
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            // User-friendly error message
            'email.regex' => 'Only official CLSU email addresses (@clsu.edu.ph or @clsu2.edu.ph) are allowed to register.',
            'clsu_id.unique' => ClsuId::TAKEN,
        ]);

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'clsu_id' => $request->clsu_id,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'patient',
            'account_status' => 'active',
            'online_status' => 'offline',
            'user_type' => null,
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
