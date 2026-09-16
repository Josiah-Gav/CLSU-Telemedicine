<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Consultation;
use App\Models\FollowUpRequest;
use App\Models\User;
use App\Enums\NotificationType;
use App\Services\ConsultationOwnershipService;
use App\Services\DashboardAnalyticsService;
use App\Services\Export\ConsultationHistoryQuery;
use App\Services\Export\ConsultationHistoryRows;
use App\Services\Export\DashboardExportRows;
use App\Services\NotificationService;
use App\Services\MedicalFileStorage;
use App\Support\CsvDownload;
use App\Support\DateRange;
use Barryvdh\DomPDF\Facade\Pdf;

class NurseController extends Controller
{
    public function __construct(
        private readonly ConsultationOwnershipService $ownershipService,
        private readonly DashboardAnalyticsService $analyticsService,
        private readonly MedicalFileStorage $medicalFiles,
    ) {
        $this->middleware('auth');
    }


    private function authorizeNurse(User $nurse)
    {
        if (Auth::user()->role !== 'nurse' || Auth::id() !== $nurse->user_id) {
            abort(403, 'Unauthorized access.');
        }
    }

    public function dashboard(User $nurse, Request $request)
    {
        $this->authorizeNurse($nurse);

        $dateRange = DateRange::fromInput(
            $request->query('range'),
            $request->query('start'),
            $request->query('end'),
            'last_30_days',
        );

        return view('nurse.dashboard', [
            'nurse' => $nurse,
            'analytics' => $this->analyticsService->forNurse($nurse, $dateRange),
            'dateRange' => $dateRange,
        ]);
    }

    /**
     * CSV/PDF export of the same analytics the nurse dashboard renders,
     * scoped and date-ranged identically — see DashboardExportRows' class
     * docblock for why the mapping step never recomputes a metric. Both
     * formats consume the same $sections, so they cannot disagree.
     */
    public function dashboardExport(User $nurse, Request $request)
    {
        $this->authorizeNurse($nurse);

        $format = (string) $request->query('format', 'csv');

        if (! in_array($format, ['csv', 'pdf'], true)) {
            abort(422, 'Unsupported export format.');
        }

        $dateRange = DateRange::fromInput(
            $request->query('range'),
            $request->query('start'),
            $request->query('end'),
            'last_30_days',
        );

        // The authenticated user's canonical display name — same
        // trim(first_name.' '.last_name) convention used everywhere else in
        // this app (see ConsultationHistoryRows::relationName()) — resolved
        // here in the controller and passed into the pure mapper; the mapper
        // never touches Auth itself.
        $generatedBy = trim(Auth::user()->first_name.' '.Auth::user()->last_name);
        $timelineLabel = DashboardExportRows::timelineLabel($dateRange->preset, $dateRange->start->toDateString(), $dateRange->end->toDateString());

        $analytics = $this->analyticsService->forNurse($nurse, $dateRange);
        $sections = DashboardExportRows::forRole('nurse', $analytics, now(), $generatedBy, $timelineLabel);
        $filename = $this->sanitizeExportFilename("Nurse {$generatedBy} {$timelineLabel} Report");

        if ($format === 'pdf') {
            // no-store mirrors CsvDownload: these reports carry
            // patient-derived aggregates and must not be cached.
            return Pdf::loadView('exports.dashboard', ['sections' => $sections])
                ->setPaper('a4', 'portrait')
                ->download($filename.'.pdf')
                ->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
        }

        return CsvDownload::stream(
            $filename.'.csv',
            DashboardExportRows::toCsvRows($sections),
        );
    }

    public function consultationInbox(User $nurse)
    {
        $this->authorizeNurse($nurse);

        $inboxData = $this->getConsultationInboxData($nurse);

        return view('nurse.consultation_inbox', [
            'nurse' => $nurse,
            'pendingRequests' => $inboxData['pendingRequests'],
            'assignedToCurrentNurse' => $inboxData['assignedToCurrentNurse'],
            'assignedToOtherNurses' => $inboxData['assignedToOtherNurses'],
        ]);
    }

    public function consultationInboxRefresh(User $nurse)
    {
        $this->authorizeNurse($nurse);

        $inboxData = $this->getConsultationInboxData($nurse);

        return response()->json([
            'pendingRequests' => $this->serializeConsultations($inboxData['pendingRequests']),
            'assignedToCurrentNurse' => $this->serializeConsultations($inboxData['assignedToCurrentNurse']),
            'assignedToOtherNurses' => $this->serializeConsultations($inboxData['assignedToOtherNurses']),
        ]);
    }

    public function followUpRequests(User $nurse)
    {
        $this->authorizeNurse($nurse);

        $pendingRequests = FollowUpRequest::with(['patient', 'consultation.request', 'consultation.physician'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return view('nurse.follow_up_requests', [
            'nurse' => $nurse,
            'pendingRequests' => $pendingRequests,
        ]);
    }

    public function forwardFollowUpRequest(Request $request, User $nurse, FollowUpRequest $followUpRequest)
    {
        $this->authorizeNurse($nurse);

        $validated = $request->validate([
            'decision_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $followUpRequest = $this->ownershipService->forwardFollowUpByNurse(
                (int) $followUpRequest->id,
                (int) Auth::id(),
                $validated['decision_notes'] ?? null
            );
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['follow_up_request' => $e->getMessage()]);
        }

        NotificationService::sendToRole(
            'physician',
            NotificationType::FOLLOW_UP_SUBMITTED,
            'Follow-up Request',
            'A patient submitted a follow-up request that requires your decision.',
            [
                'follow_up_request_id' => $followUpRequest->id,
                'consultation_id' => $followUpRequest->consultation_id,
                'patient_id' => $followUpRequest->patient_id,
            ]
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Follow-up request forwarded to physician review.',
            ]);
        }

        return back()->with('status', 'Follow-up request forwarded to physician review.');
    }

    public function rejectFollowUpRequest(Request $request, User $nurse, FollowUpRequest $followUpRequest)
    {
        $this->authorizeNurse($nurse);

        $validated = $request->validate([
            'decision_notes' => 'required|string|max:2000',
        ]);

        try {
            $followUpRequest = $this->ownershipService->rejectFollowUpByNurse(
                (int) $followUpRequest->id,
                (int) Auth::id(),
                (string) $validated['decision_notes']
            );
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['follow_up_request' => $e->getMessage()]);
        }

        NotificationService::send(
            $followUpRequest->patient_id,
            NotificationType::FOLLOW_UP_REJECTED,
            'Follow-up Request Rejected',
            'Your follow-up request was rejected. Reason: ' . $validated['decision_notes'],
            [
                'follow_up_request_id' => $followUpRequest->id,
                'consultation_id' => $followUpRequest->consultation_id,
            ]
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Follow-up request rejected.',
            ]);
        }

        return back()->with('status', 'Follow-up request rejected.');
    }

    /**
     * Filtering and query construction live in ConsultationHistoryQuery so the
     * export below reuses the exact same semantics — mirrors
     * PhysicianController::consultationHistory(). Authorization stays here
     * (authorizeNurse above); the service never touches Auth.
     */
    public function consultationHistory(User $nurse, Request $request)
    {
        $this->authorizeNurse($nurse);

        $search = trim((string) $request->query('search', ''));

        $filters = ConsultationHistoryQuery::normalizeFilters(
            $request->query('date_filter', 'all'),
            $request->query('status', 'all'),
            $request->query('consultation_type', 'all'),
        ) + ['search' => $search];

        $historyConsultations = ConsultationHistoryQuery::forNurse((int) Auth::id(), $filters)->get();

        // No decorateHasExistingFollowUp() here: that decoration only drives
        // the physician page's "Schedule Follow-up" button, which nurses
        // cannot do.
        if ($request->ajax()) {
            return response()->json([
                'html' => view('nurse.partials.consultation_history_table', [
                    'historyConsultations' => $historyConsultations,
                ])->render(),
            ]);
        }

        return view('nurse.consultation_history', [
            'nurse' => $nurse,
            'historyConsultations' => $historyConsultations,
            'filters' => $filters,
        ]);
    }

    /**
     * CSV/PDF export of this nurse's own consultation history. Filtering,
     * scoping, and ordering are identical to consultationHistory() above —
     * both call ConsultationHistoryQuery::forNurse(), so the export can
     * never disagree with what the HTML page currently shows for the same
     * query string. Mirrors PhysicianController::consultationHistoryExport().
     */
    public function consultationHistoryExport(User $nurse, Request $request)
    {
        $this->authorizeNurse($nurse);

        $format = (string) $request->query('format', 'csv');

        if (! in_array($format, ['csv', 'pdf'], true)) {
            abort(422, 'Unsupported export format.');
        }

        $search = trim((string) $request->query('search', ''));

        $filters = ConsultationHistoryQuery::normalizeFilters(
            $request->query('date_filter', 'all'),
            $request->query('status', 'all'),
            $request->query('consultation_type', 'all'),
        ) + ['search' => $search];

        $historyConsultations = ConsultationHistoryQuery::forNurse((int) Auth::id(), $filters)->get();

        $rows = ConsultationHistoryRows::nurseRows($historyConsultations);

        // The authenticated user's canonical display name — resolved from
        // Auth explicitly here (not from the route-bound $nurse, even though
        // authorizeNurse() above already guarantees they're the same user)
        // so every export action obtains it the same way.
        $generatedBy = trim(Auth::user()->first_name.' '.Auth::user()->last_name);
        $timelineLabel = ConsultationHistoryRows::timelineLabel($filters['date_filter'] ?? 'all');

        $title = "Nurse {$generatedBy} {$timelineLabel} History Report";
        $meta = array_merge([
            ['Role', 'Nurse'],
            ['Owner', trim($nurse->first_name.' '.$nurse->last_name)],
            ['Generated By', $generatedBy],
        ], ConsultationHistoryRows::filterSummaryRows($filters), [
            ['Generated', now()->format('Y-m-d H:i')],
        ]);

        $filename = $this->sanitizeExportFilename($title);

        if ($format === 'pdf') {
            $totalCount = count($rows);
            $pdfRows = array_slice($rows, 0, ConsultationHistoryRows::PDF_ROW_CAP);

            return Pdf::loadView('exports.consultation-history', [
                'title' => $title,
                'meta' => $meta,
                'headers' => ConsultationHistoryRows::NURSE_HEADERS,
                'rows' => $pdfRows,
                'totalCount' => $totalCount,
                'truncated' => $totalCount > ConsultationHistoryRows::PDF_ROW_CAP,
                'rowCap' => ConsultationHistoryRows::PDF_ROW_CAP,
            ])
                ->setPaper('a4', 'landscape')
                ->download($filename.'.pdf')
                ->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
        }

        return CsvDownload::stream(
            $filename.'.csv',
            ConsultationHistoryRows::toCsvRows($title, $meta, ConsultationHistoryRows::NURSE_HEADERS, $rows),
        );
    }

    private function getConsultationInboxData(User $nurse): array
    {
        $currentNurseId = (int) $nurse->user_id;

        $pendingRequests = Consultation::with(['patient', 'physician'])
            ->where('request_status', 'pending')
            ->orderByDesc('submitted_at')
            ->get();

        $assignedRequests = Consultation::with(['patient', 'nurse', 'physician'])
            ->whereIn('request_status', ['reviewed', 'assigned', 'active', 'scheduled'])
            ->orderByDesc('submitted_at')
            ->get();

        return [
            'pendingRequests' => $pendingRequests,
            'assignedToCurrentNurse' => $assignedRequests
                ->where('assigned_nurse_id', $currentNurseId)
                ->values(),
            'assignedToOtherNurses' => $assignedRequests
                ->filter(fn ($request) => (int) $request->assigned_nurse_id !== $currentNurseId)
                ->values(),
        ];
    }

    private function serializeConsultations($consultations): array
    {
        return $consultations->map(function ($request) {
            return [
                'request_id' => $request->request_id,
                'patient_id' => $request->patient_id,
                'patient_name' => trim(optional($request->patient)->first_name.' '.optional($request->patient)->last_name) ?: 'Unknown Patient',
                'patient_is_online' => $this->isUserOnline($request->patient),
                'concern_category' => $request->concern_category,
                'submitted_at' => $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : null,
                'request_status' => $request->request_status,
                'assigned_nurse_id' => $request->assigned_nurse_id,
                'assigned_nurse_name' => trim(optional($request->nurse)->first_name.' '.optional($request->nurse)->last_name) ?: null,
                'assigned_physician_id' => $request->assigned_physician_id,
                'assigned_physician_name' => trim(optional($request->physician)->first_name.' '.optional($request->physician)->last_name) ?: null,
                'priority_level' => $request->priority_level,
                'symptoms_desc' => $request->symptoms_desc,
                'online_reason' => $request->online_reason,
                'additional_information' => $request->additional_information,
                // Keyed by MedicalFileStorage::attachmentKey(), the single
                // definition AttachmentController::show() matches against, so a
                // change to the stored reference format cannot desynchronise
                // the link from the lookup.
                'file_attachments' => array_map(function ($reference) use ($request) {
                    return url('/consultations/'.$request->request_id.'/attachments/'.$this->medicalFiles->attachmentKey($reference));
                }, $request->file_attachments ?? []),
            ];
        })->values()->all();
    }

    private function isUserOnline(?User $user): bool
    {
        return $user
            && $user->online_status === 'online'
            && $user->last_seen_at
            && $user->last_seen_at->gt(now()->subMinutes(2));
    }

    /**
     * Strips characters a filesystem/browser download would reject
     * (/ : \ * ? " < > |) from an otherwise-readable export report name —
     * e.g. "Nurse Maria Santos Last 30 Days Report" stays exactly as-is,
     * spaces included, since only these nine characters are actually unsafe
     * in a filename.
     */
    private function sanitizeExportFilename(string $name): string
    {
        return str_replace(['/', ':', '\\', '*', '?', '"', '<', '>', '|'], '-', $name);
    }
}
