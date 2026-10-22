<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEncounterToChis;
use App\Models\ConsultationSession;
use App\Services\ChisClient;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Admin view of CHIS encounter sync status, with Retry. Sync status only:
 * this page never loads or shows clinical data.
 */
class ChisSyncController extends Controller
{
    public const STATUSES = ['pending', 'synced', 'failed', 'skipped'];

    private function authorizeAdmin(): void
    {
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }
    }

    public function index(Request $request, ChisClient $chis): View
    {
        $this->authorizeAdmin();

        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;

        $sessions = ConsultationSession::query()
            ->select(['id', 'request_id', 'physician_id', 'completed_at', 'chis_sync_status', 'chis_reference', 'chis_attempts', 'chis_last_error'])
            ->whereNotNull('chis_sync_status')
            ->when($status, fn ($query) => $query->where('chis_sync_status', $status))
            ->with([
                'request:request_id,patient_id',
                'request.patient:user_id,first_name,last_name,clsu_id',
                'physician:user_id,first_name,last_name',
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $counts = ConsultationSession::query()
            ->whereNotNull('chis_sync_status')
            ->selectRaw('chis_sync_status, COUNT(*) as total')
            ->groupBy('chis_sync_status')
            ->pluck('total', 'chis_sync_status');

        // Cached: when CHIS is unreachable, health() waits up to the configured
        // connect/request timeout, which would otherwise delay every page load.
        $chisReachable = Cache::remember('chis.health', 60, fn () => $chis->health());

        return view('admin.chis-sync.index', compact('sessions', 'counts', 'status', 'chisReachable'));
    }

    public function retry(ConsultationSession $session): RedirectResponse
    {
        $this->authorizeAdmin();

        // Conditional, so a synced or skipped session can never be re-sent
        // (skipped is never re-sent from here; only chis:send-skipped requeues no_consent rows).
        $claimed = ConsultationSession::whereKey($session->id)
            ->whereIn('chis_sync_status', ['failed', 'pending'])
            ->update(['chis_sync_status' => 'pending', 'chis_last_error' => null]);

        if (! $claimed) {
            return back()->with('warning', 'Only failed or pending syncs can be retried. Synced and skipped consultations are never re-sent.');
        }

        // ShouldBeUnique silently drops a dispatch while an earlier attempt is
        // still queued or waiting out its backoff, so check first and tell the
        // admin the truth instead of reporting a retry that never happened.
        $job = new SyncEncounterToChis($session->id);
        $lock = new UniqueLock(Cache::store());

        if (! $lock->acquire($job)) {
            return back()->with('status', "Consultation #{$session->id} already has a sync attempt waiting; it will be sent at its next scheduled retry (within the hour).");
        }

        $lock->release($job);

        try {
            dispatch($job);
        } catch (Throwable $e) {
            Log::warning('CHIS encounter sync retry could not be queued.', [
                'session_id' => $session->id,
                'exception' => $e::class,
            ]);

            return back()->with('warning', 'Marked for retry, but the job could not be queued right now. It will be retried automatically.');
        }

        return back()->with('status', "Retry queued for consultation #{$session->id}.");
    }
}
