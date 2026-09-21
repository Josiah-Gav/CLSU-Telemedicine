<?php

use App\Http\Controllers\Api\ChisIntegrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CHIS integration routes
|--------------------------------------------------------------------------
|
| These simulate the RESTful data exchange described in Objective 8: a
| future Comprehensive Health Information System (CHIS) pulling a closed
| consultation's encounter summary from this system, and this system
| pulling patient identity/medical-context from CHIS. CHIS does not exist
| yet, so both directions are served here — see App\Contracts\ChisClient
| and App\Services\Chis\FakeChisClient for the "receive" side's stand-in.
|
| Every route requires a Sanctum token scoped to the matching ability.
| Issue one with `php artisan chis:issue-token`.
*/
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::middleware('ability:chis:read-encounters')
        ->get('/consultations/{consultation}/encounter-summary', [ChisIntegrationController::class, 'encounterSummary'])
        ->name('api.chis.encounter-summary');

    Route::middleware('ability:chis:read-patients')->group(function () {
        Route::get('/patients/{clsu_id}/identity', [ChisIntegrationController::class, 'identity'])
            ->name('api.chis.patient-identity');

        Route::get('/patients/{clsu_id}/medical-profile', [ChisIntegrationController::class, 'medicalProfile'])
            ->name('api.chis.patient-medical-profile');
    });
});
