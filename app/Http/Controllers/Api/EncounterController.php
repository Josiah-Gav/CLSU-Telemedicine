<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Chis\ChisUnavailableException;
use App\Http\Controllers\Admin\ChisSyncController;
use App\Http\Controllers\Controller;
use App\Jobs\SyncEncounterToChis;
use App\Models\ChisAuditLog;
use App\Models\ConsultationSession;
use App\Services\ChisEncounterSync;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * CHIS sync status over a token-protected API. Sync status only: never patient
 * names, CLSU IDs or clinical data.
 */
class EncounterController extends Controller
{
    /** The token owner must still be an active admin, and the token must carry the ability. */
    private function authorizeToken(Request $request, string $ability): void
    {
        $user = $request->user();

        abort_unless(
            $user && $user->role === 'admin' && $user->account_status === 'active' && $user->tokenCan($ability),
            403
        );
    }

    private function present(ConsultationSession $session): array
    {
        return [
            'telemed_encounter_id' => $session->id,
            'completed_at' => $session->completed_at?->toIso8601String(),
            'chis_sync_status' => $session->chis_sync_status,
            'chis_reference' => $session->chis_reference,
            'chis_attempts' => $session->chis_attempts,
            'chis_last_error' => $session->chis_last_error,
            'chis_last_error_text' => ChisEncounterSync::describeError($session->chis_last_error),
        ];
    }

    private const COLUMNS = ['id', 'completed_at', 'chis_sync_status', 'chis_reference', 'chis_attempts', 'chis_last_error'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeToken($request, 'encounters:read');

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ChisSyncController::STATUSES, true) ? $status : null;

        $page = ConsultationSession::query()
            ->select(self::COLUMNS)
            ->whereNotNull('chis_sync_status')
            ->when($status, fn ($q) => $q->where('chis_sync_status', $status))
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($s) => $this->present($s))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, ConsultationSession $session): JsonResponse
    {
        $this->authorizeToken($request, 'encounters:read');
        abort_if($session->chis_sync_status === null, 404);

        return response()->json(['data' => $this->present($session)]);
    }

    /**
     * Sends the summary to CHIS now and returns CHIS's acknowledgement, so the
     * caller (Postman) sees it immediately instead of waiting on a queue.
     */
    public function sync(Request $request, ConsultationSession $session): JsonResponse
    {
        $this->authorizeToken($request, 'encounters:sync');
        abort_if($session->chis_sync_status === null, 404);

        $owner = $request->user();

        if ($session->chis_sync_status === 'skipped') {
            // Consent is forward-only; skipped is final.
            return response()->json([
                'message' => 'This consultation was skipped and is never sent to CHIS.',
                'code' => 'NOT_ELIGIBLE',
                'reason' => $session->chis_last_error,
                'reason_text' => ChisEncounterSync::describeError($session->chis_last_error),
            ], 409);
        }

        $inProgress = fn () => response()->json([
            'message' => 'A sync attempt for this consultation is already queued or running.',
            'code' => 'SYNC_IN_PROGRESS',
        ], 409);

        $job = new SyncEncounterToChis($session->id, $owner->user_id, 'api');
        $lock = new UniqueLock(Cache::store());

        if (! $lock->acquire($job)) {
            return $inProgress();
        }
        $lock->release($job);

        // Resending a synced session is allowed: it demonstrates CHIS idempotency.
        $claimed = ConsultationSession::whereKey($session->id)
            ->whereIn('chis_sync_status', ['pending', 'failed', 'synced'])
            ->update(['chis_sync_status' => 'pending', 'chis_last_error' => null]);

        if (! $claimed) {
            return $inProgress();
        }

        $lastAuditId = (int) ChisAuditLog::max('id');

        try {
            SyncEncounterToChis::dispatchSync($session->id, $owner->user_id, 'api');
        } catch (ChisUnavailableException) {
            // Transient: the session stays pending with its error recorded and
            // the sweeper retries it. Not a server error.
        }

        // Only this call's own audit row: a queued job for another session may
        // have written one in between.
        $httpStatus = ChisAuditLog::where('id', '>', $lastAuditId)
            ->where('action', 'encounter_push')
            ->where('consultation_session_id', $session->id)
            ->where('source', 'api')
            ->where('actor_user_id', $owner->user_id)
            ->latest('id')
            ->value('http_status');

        return response()->json([
            'data' => $this->present($session->fresh()) + ['chis_http_status' => $httpStatus],
        ]);
    }
}
