<?php

namespace App\Providers;

use App\Contracts\ChisClient;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Policies\ConsultationPolicy;
use App\Policies\ConsultationSessionPolicy;
use App\Services\Chis\FakeChisClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap FakeChisClient for a RealChisClient here once CHIS exposes an
        // actual API — see App\Contracts\ChisClient's docblock.
        $this->app->bind(ChisClient::class, FakeChisClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Consultation::class, ConsultationPolicy::class);
        Gate::policy(ConsultationSession::class, ConsultationSessionPolicy::class);
    }
}
