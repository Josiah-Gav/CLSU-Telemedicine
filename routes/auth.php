<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\StaffInvitationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    // Deliberately looser than the throttle:6,1 used elsewhere in this file.
    // Laravel throttles a guest route by IP, and CLSU campus traffic arrives
    // NATed behind a small number of addresses — during a demo or an
    // orientation session many legitimate students register from one apparent
    // IP within the same minute, and 6 would lock them out. Scripted abuse is
    // already bounded by registration itself: the address must be unique and
    // must match the @clsu.edu.ph / @clsu2.edu.ph rule in
    // RegisteredUserController, so an attacker cannot mint accounts freely.
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    // The mail-bomb gate. config/auth.php already throttles the broker at 60s
    // per *email address*, which stops one mailbox being flooded; this adds the
    // per-IP half, which is what stops a script walking a list of addresses and
    // burning the SMTP quota the whole application depends on for first login.
    // Matches the throttle:6,1 used by the other credential-bearing guest
    // endpoints in this file.
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    // Same limit, different reason: this endpoint accepts a one-time reset
    // token, so an unthrottled POST is an offline-free guessing surface.
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');

    // Nurse/physician account activation. Deliberately outside the auth+verified
    // group in routes/web.php: the invitee has no password and is not logged in.
    Route::get('staff/activate/{token}', [StaffInvitationController::class, 'create'])
        ->middleware('throttle:6,1')
        ->name('staff.activate');

    Route::post('staff/activate', [StaffInvitationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('staff.activate.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
