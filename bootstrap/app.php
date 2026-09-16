<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\TrackUserPresence;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // EnsureAccountIsActive runs first: a suspended account must be signed
        // out before TrackUserPresence refreshes its presence, or a suspended
        // physician keeps looking online and holding consultation intake open.
        $middleware->web(append: [
            EnsureAccountIsActive::class,
            TrackUserPresence::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'presence/heartbeat',
        ]);

        // TLS is terminated by a proxy in every environment this application
        // runs in, and the proxy forwards a plain HTTP connection with
        // X-Forwarded-* headers. Without trusting it, Symfony's
        // Request::isSecure() only checks $_SERVER['HTTPS'] (unset) and Laravel
        // generates http:// asset and route URLs even though the browser is on
        // https://, which breaks @vite() assets as mixed content and makes
        // Jitsi's getUserMedia refuse to run outside a secure context.
        //
        // '*' rather than a fixed address list. This was previously pinned to
        // ['127.0.0.1', '::1'], which is only correct for the local ngrok setup,
        // where the ngrok agent connects to Apache over loopback. A platform
        // load balancer (Railway) forwards from a dynamic internal address that
        // is not loopback and that the platform does not publish as a stable
        // range, so a fixed list cannot be written for it. Laravel supports '*'
        // for exactly this case — see Illuminate\Http\Middleware\TrustProxies.
        //
        // The precondition that makes '*' safe is that the application is never
        // reachable except through that proxy, so a client has no way to present
        // its own X-Forwarded-* headers. That holds on Railway, where only the
        // edge proxy can reach the container. It must be re-examined before
        // putting this application anywhere it is directly internet-reachable.
        // Local XAMPP is unaffected either way: with no proxy in front of it
        // there are no forwarded headers to trust.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Staff invitation and password reset links both submit their one-time
        // secret as a 'token' field. Without this it is flashed back as old
        // input on any validation failure, persisting the live token in the
        // session. Joins the framework defaults rather than replacing them.
        $exceptions->dontFlash(['token']);
    })->create();
