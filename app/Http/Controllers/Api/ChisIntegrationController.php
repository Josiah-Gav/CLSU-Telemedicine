<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ChisClient;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use Illuminate\Http\JsonResponse;

/**
 * The two directions of the simulated CHIS data exchange (Objective 8):
 *
 * - encounterSummary() is the "send" direction — CHIS pulling a closed
 *   consultation's clinical summary from this system. Real data, served
 *   from this application's own tables.
 * - identity() / medicalProfile() are the "receive" direction — this
 *   system pulling identity and limited clinical context from CHIS.
 *   Backed by ChisClient, currently FakeChisClient (see AppServiceProvider).
 *
 * Every route here sits behind auth:sanctum plus an ability check (see
 * routes/api.php) — the caller is a ChisIntegrationClient token, never a
 * logged-in User session.
 */
class ChisIntegrationController extends Controller
{
    public function __construct(private readonly ChisClient $chis) {}

    public function encounterSummary(Consultation $consultation): JsonResponse
    {
        $consultation->loadMissing(['patient', 'physician', 'consultationSession']);
        $session = $consultation->consultationSession;

        if (! $session) {
            return response()->json([
                'message' => 'This consultation request has no clinical session yet.',
            ], 404);
        }

        return response()->json([
            'encounter_reference' => $consultation->request_id,
            'patient_identifier' => $consultation->patient?->clsu_id,
            'concern_category' => $consultation->concern_category,
            'attending_physician' => $consultation->physician?->clsu_id,
            'diagnosis' => $session->diagnosis,
            'assessment' => $session->assessment,
            'plan' => $session->plan,
            'recommendations' => $session->recommendations,
            'consultation_status' => $session->consultation_status,
            'completed_at' => $session->completed_at?->toIso8601String(),
            'follow_up_required' => $session->follow_up_required,
            'follow_up_date' => $session->follow_up_date?->toDateString(),
            'prescription_reference' => $session->prescription_file_name,
        ]);
    }

    public function identity(string $clsu_id): JsonResponse
    {
        $identity = $this->chis->getIdentity($clsu_id);

        if (! $identity) {
            return response()->json(['message' => 'No matching CHIS identity.'], 404);
        }

        return response()->json($identity);
    }

    public function medicalProfile(string $clsu_id): JsonResponse
    {
        $profile = $this->chis->getMedicalProfile($clsu_id);

        if (! $profile) {
            return response()->json(['message' => 'No matching CHIS medical profile.'], 404);
        }

        return response()->json($profile);
    }
}
