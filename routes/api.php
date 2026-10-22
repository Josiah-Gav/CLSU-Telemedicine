<?php

use App\Http\Controllers\Api\EncounterController;
use Illuminate\Support\Facades\Route;

// Token-only (Sanctum bearer tokens; see config/sanctum.php 'guard' => []).
// Every action also checks the owner is still an active admin and that the
// token carries the needed ability. Sync status only: no clinical data.
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('encounters', [EncounterController::class, 'index']);
    Route::get('encounters/{session}', [EncounterController::class, 'show']);
    Route::post('encounters/{session}/sync', [EncounterController::class, 'sync']);
});
