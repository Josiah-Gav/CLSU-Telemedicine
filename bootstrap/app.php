<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\TrackUserPresence;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum ships these ability-check middleware but only auto-aliases
        // them under Jetstream/Fortify scaffolding; routes/api.php uses the
        // 'ability' alias directly, so it is registered here instead.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

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

        // When TLS is terminated by a proxy (a load balancer, CDN such as
        // Cloudflare, or ngrok), the proxy forwards a plain HTTP connection with
        // X-Forwarded-* headers. Without trusting it, Symfony's
        // Request::isSecure() only checks $_SERVER['HTTPS'] (unset) and Laravel
        // generates http:// asset and route URLs even though the browser is on
        // https://, which breaks @vite() assets as mixed content and makes
        // Jitsi's getUserMedia refuse to run outside a secure context.
        //
        // '*' rather than a fixed address list. This was previously pinned to
        // ['127.0.0.1', '::1'], which is only correct for the local ngrok setup,
        // where the ngrok agent connects to Apache over loopback. A load
        // balancer or CDN forwards from addresses that are not loopback, and
        // Laravel supports '*' for proxies without a fixed address — see
        // Illuminate\Http\Middleware\TrustProxies.
        //
        // The precondition that makes '*' safe is that the application is never
        // reachable except through that proxy. If clients can reach the web
        // server directly, they can send their own X-Forwarded-For (defeating
        // the per-IP login and guest-auth throttles) and X-Forwarded-Host/Proto.
        // Whether the production Hostinger deployment sits behind such a proxy
        // is decided by whoever deploys it; docs/HOSTINGER_QA.md records the
        // decision that has to be made. Local XAMPP is unaffected in practice.
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
