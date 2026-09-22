<?php

namespace App\Providers;

use App\Mail\Transport\SendGridApiTransport;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Policies\ConsultationPolicy;
use App\Policies\ConsultationSessionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
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
        Gate::policy(Consultation::class, ConsultationPolicy::class);
        Gate::policy(ConsultationSession::class, ConsultationSessionPolicy::class);

        // Not one of Laravel's built-in mail transports (smtp/ses/postmark/
        // resend/sendmail). Registered here per Laravel's documented custom
        // transport pattern; see config/mail.php's 'sendgrid' mailer and
        // SendGridApiTransport's docblock for why this exists over SMTP.
        Mail::extend('sendgrid', fn (array $config) => new SendGridApiTransport($config['key'] ?? ''));
    }
}
