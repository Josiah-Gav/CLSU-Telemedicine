<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\UserManagementController;
use App\Models\Consultation;
use App\Models\FollowUpRequest;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\Export\DashboardExportRows;
use App\Services\PhysicianAvailabilityService;
use App\Support\CsvDownload;
use App\Support\DateRange;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardAnalyticsService $analyticsService,
        private readonly PhysicianAvailabilityService $availabilityService,
    ) {}

    /** Mirrors Admin\UserManagementController::authorizeAdmin() — role check only, no per-record ownership. */
    private function authorizeAdmin(): void
    {
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }
    }

    /**
     * CSV/PDF export of the same analytics the admin dashboard renders,
     * date-ranged identically — see DashboardExportRows' class docblock for
     * why the mapping step never recomputes a metric. Both formats consume
     * the same $sections, so they cannot disagree. The symptom sections
     * carry SymptomAnalytics' k=3 suppression through unchanged: the
     * suppressed count is reported, the suppressed terms never are.
     */
    public function adminDashboardExport(Request $request)
    {
        $this->authorizeAdmin();

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

        $analytics = $this->analyticsService->forAdmin($dateRange);
        $sections = DashboardExportRows::forRole('admin', $analytics, now(), $generatedBy, $timelineLabel);
        $filename = $this->sanitizeExportFilename("Admin {$generatedBy} {$timelineLabel} Report");

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

    /**
     * Phase 2 (IA/UX): the admin dashboard's "Needs Attention" band — the
     * one thing on that dashboard an admin actually has to act on, ahead of
     * the analytics below it. Reuses
     * Admin\UserManagementController::invitationStates() rather than
     * re-deriving "pending"/"expired" a second way that could drift from
     * what admin/users/index.blade.php shows for the same accounts.
     *
     * @return array{pending: int, expired: int}
     */
    private function staffInvitationSummary(): array
    {
        $invitedUsers = User::where('account_status', 'inactive')
            ->whereIn('role', ['nurse', 'physician'])
            ->get();

        $states = UserManagementController::invitationStates($invitedUsers)->filter();

        return [
            'pending' => $states->where('state', 'pending')->count(),
            'expired' => $states->where('state', 'expired')->count(),
        ];
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        // Check the user's role and return the corresponding view
        // Note: This assumes you have a 'role' column on your users table
        switch ($user->role) {
            case 'patient':
                $patientInfo = Auth::user();
                $activeConsultation = $this->getPatientActiveConsultation($patientInfo->user_id);
                $activeConsultationSummary = $this->getConsultationSummary($activeConsultation);
                $followUpStatus = $this->getPatientFollowUpStatus($patientInfo->user_id);
                $physicianFollowUp = $this->getPhysicianInitiatedFollowUp($patientInfo->user_id);

                // Same single source of truth as newconsultation()'s
                // informational banner. The next window is only worth a
                // query when intake is actually unavailable right now.
                $intakeAvailable = $this->availabilityService->isServiceAvailable();
                $nextScheduledWindow = $intakeAvailable ? null : $this->availabilityService->nextScheduledWindow();
                $weeklySchedule = $this->availabilityService->weeklyScheduleOverview();
                $tamEvaluationSessionId = $this->getPatientMostRecentCompletedConsultationSessionId($patientInfo->user_id);

                return view('patient.dashboard', compact('patientInfo', 'activeConsultation', 'activeConsultationSummary', 'followUpStatus', 'physicianFollowUp', 'intakeAvailable', 'nextScheduledWindow', 'weeklySchedule', 'tamEvaluationSessionId'));
            case 'physician':
                // Physicians land here on first login/verification (Breeze's
                // redirect()->intended(route('dashboard')) — see
                // AuthenticatedSessionController) even though normal
                // navigation always links to physician.dashboard directly.
                // Both paths must carry the same analytics, so this mirrors
                // PhysicianController::dashboard() rather than redirecting —
                // a redirect would change the response from 200 to 302 and
                // break MobileBottomNavigationTest's existing assertions
                // that GET /dashboard renders physician nav content directly.
                $physicianDateRange = DateRange::fromInput(
                    $request->query('range'),
                    $request->query('start'),
                    $request->query('end'),
                    'this_month',
                );

                // Bundled on the service so this branch and
                // PhysicianController::dashboard() can never disagree about
                // what the intake card shows.
                $physicianIntakeSummary = $this->availabilityService->dashboardIntakeSummary($user);

                return view('physician.dashboard', [
                    'analytics' => $this->analyticsService->forPhysician($user, $physicianDateRange),
                    'dateRange' => $physicianDateRange,
                    'intake' => $physicianIntakeSummary['intake'],
                    'todaySchedule' => $physicianIntakeSummary['today_schedule'],
                    'showIntakeScheduleWarning' => $physicianIntakeSummary['show_schedule_warning'],
                    'intakeRoutes' => [
                        'open_url' => route('physician.consultation_intake.open', ['physician' => $user->user_id]),
                        'close_url' => route('physician.consultation_intake.close', ['physician' => $user->user_id]),
                    ],
                ]);
            case 'nurse':
                return redirect()->route('nurse.dashboard', ['nurse' => $user]);
            case 'admin':
                $dateRange = DateRange::fromInput(
                    $request->query('range'),
                    $request->query('start'),
                    $request->query('end'),
                    'last_30_days',
                );

                return view('admin.dashboard', [
                    'analytics' => $this->analyticsService->forAdmin($dateRange),
                    'dateRange' => $dateRange,
                    'staffInvitationSummary' => $this->staffInvitationSummary(),
                ]);
            default:
                abort(403, 'Unauthorized action. Role not recognized.');
        }
    }

    public function activeConsultation()
    {
        $patient = Auth::user();

        if ($patient->role !== 'patient') {
            abort(403, 'Unauthorized access.');
        }

        $consultation = $this->getPatientActiveConsultation($patient->user_id);
        $physicianFollowUp = $this->getPhysicianInitiatedFollowUp($patient->user_id);

        return response()->json([
            'consultation' => $this->serializePatientConsultation($consultation),
            'physician_follow_up' => $physicianFollowUp,
        ]);
    }

    public function newconsultation()
    {
        $patientInfo = Auth::user();

        // Security checkpoint: block physicians or nurses if they try to access this page
        if ($patientInfo->role !== 'patient') {
            abort(403, 'Unauthorized access.');
        }

        $hasActiveConsultation = Consultation::where('patient_id', auth()->id())
            ->whereIn('request_status', ['pending', 'reviewed', 'assigned', 'scheduled', 'active'])
            ->where(function ($query) {
                $query->whereDoesntHave('consultationSession')
                    ->orWhereHas('consultationSession', function ($sessionQuery) {
                        $sessionQuery->whereIn('consultation_status', ['scheduled', 'active']);
                    });
            })
            ->exists();

        if ($hasActiveConsultation) {
            return redirect()->route('dashboard')->with('status', 'You already have an active consultation request.');
        }

        // Server-rendered so the page never flashes "Available" before
        // correcting itself. isServiceAvailable() is the same single source of
        // truth ConsultationController::store() enforces — this view datum is
        // purely informational and never itself gates the submission.
        return view('patient.newconsultation', [
            'patient' => $patientInfo,
            'intakeAvailable' => $this->availabilityService->isServiceAvailable(),
        ]);
    }

    private function getPatientActiveConsultation(int $patientId): ?Consultation
    {
        return Consultation::with('consultationSession.slot')
            ->where('patient_id', $patientId)
            ->whereIn('request_status', ['pending', 'reviewed', 'assigned', 'scheduled', 'active'])
            ->where(function ($query) {
                $query->whereDoesntHave('consultationSession')
                    ->orWhereHas('consultationSession', function ($sessionQuery) {
                        $sessionQuery->whereIn('consultation_status', ['scheduled', 'active']);
                    });
            })
            ->latest('submitted_at')
            ->first();
    }

    // The most recently completed consultation, if any — used only to hand the
    // TAM evaluation card a session id. getPatientActiveConsultation() above
    // explicitly excludes 'completed', so this is a separate query rather than
    // a shared one.
    private function getPatientMostRecentCompletedConsultationSessionId(int $patientId): ?int
    {
        $consultation = Consultation::with('consultationSession')
            ->where('patient_id', $patientId)
            ->where('request_status', 'completed')
            ->whereHas('consultationSession', function ($sessionQuery) {
                $sessionQuery->where('consultation_status', 'completed');
            })
            ->latest('submitted_at')
            ->first();

        return $consultation?->consultationSession?->id;
    }

    private function getConsultationSummary(?Consultation $consultation): ?string
    {
        if (! $consultation) {
            return null;
        }

        if (is_array($consultation->symptoms_desc)) {
            return collect($consultation->symptoms_desc)
                ->pluck('name')
                ->filter()
                ->join(', ');
        }

        return $consultation->symptoms_desc;
    }

    private function getPatientFollowUpStatus(int $patientId): array
    {
        $followUpRequest = FollowUpRequest::with('consultation.request')
            ->where('patient_id', $patientId)
            ->latest('updated_at')
            ->first();

        if (! $followUpRequest) {
            return [
                'exists' => false,
                'status' => 'none',
                'status_label' => 'No follow-up request',
                'status_badge_class' => 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold bg-slate-100 text-slate-700',
                'updated_at' => null,
                'decision_notes' => null,
            ];
        }

        $originalRequest = optional($followUpRequest->consultation)->request;

        return [
            'request_id' => $followUpRequest->id,
            'exists' => true,
            'status' => $followUpRequest->status,
            'status_label' => ucfirst($followUpRequest->status),
            'status_badge_class' => $this->getFollowUpStatusBadgeClass($followUpRequest->status),
            'updated_at' => optional($followUpRequest->updated_at)->format('M d, Y'),
            'decision_notes' => $followUpRequest->decision_notes,
            'details_url' => $originalRequest ? route('consultations.show', $originalRequest) : null,
        ];
    }

    private function getFollowUpStatusBadgeClass(string $status): string
    {
        $baseClass = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold ';

        return match ($status) {
            'approved' => $baseClass.'bg-emerald-100 text-emerald-700',
            'pending', 'forwarded' => $baseClass.'bg-yellow-100 text-yellow-700',
            'rejected' => $baseClass.'bg-red-100 text-red-700',
            default => $baseClass.'bg-slate-100 text-slate-700',
        };
    }

    /**
     * Both follow-up creation paths produce a type = 'follow_up' Consultation,
     * so the type alone cannot say who started it. The discriminator is the
     * session's follow_up_request_id: the patient-initiated path (patient asks
     * -> nurse forwards -> physician approves, ConsultationOwnershipService::
     * decideFollowUpByPhysician) links the new session back to the originating
     * FollowUpRequest, while a physician acting on their own (PhysicianController::
     * createPhysicianFollowUp -> createFollowUpConsultationFromSource, called
     * without a $followUpRequestId) leaves it null. Without the whereHas below
     * this card also appeared for follow-ups the patient themselves requested.
     */
    private function getPhysicianInitiatedFollowUp(int $patientId): ?array
    {
        $consultation = Consultation::query()
            ->with(['consultationSession.slot', 'physician'])
            ->where('patient_id', $patientId)
            ->where('type', 'follow_up')
            ->whereHas('consultationSession', function ($sessionQuery) {
                $sessionQuery->whereNull('follow_up_request_id');
            })
            ->whereIn('request_status', ['scheduled', 'active'])
            ->orderByRaw("CASE request_status WHEN 'scheduled' THEN 1 WHEN 'active' THEN 2 ELSE 3 END")
            ->latest('submitted_at')
            ->first();

        if (! $consultation) {
            return null;
        }

        $session = $consultation->consultationSession;

        return [
            'request_id' => $consultation->request_id,
            'consultation_type_label' => 'Follow-up',
            'request_status' => $consultation->request_status,
            'status_label' => ucfirst($consultation->request_status),
            'status_badge_class' => $this->getPatientStatusBadgeClass($consultation->request_status),
            'submitted_at' => optional($consultation->submitted_at)->format('M d, Y'),
            'physician_name' => trim(optional($consultation->physician)->first_name.' '.optional($consultation->physician)->last_name) ?: 'Your physician',
            'consultation_status' => $consultation->request_status,
            'scheduled_slot' => $consultation->consultationSession?->slot ? [
                'slot_date' => $consultation->consultationSession->slot->slot_date?->format('M d, Y') ?? (string) $consultation->consultationSession->slot->slot_date,
                'start_time' => $consultation->consultationSession->slot->start_time,
                'end_time' => $consultation->consultationSession->slot->end_time,
            ] : null,
            'consultation_session' => $session ? [
                'id' => $session->id,
                'consultation_status' => $session->consultation_status,
                'scheduled_slot' => $session->slot ? [
                    'slot_date' => $session->slot->slot_date?->format('M d, Y') ?? (string) $session->slot->slot_date,
                    'start_time' => $session->slot->start_time,
                    'end_time' => $session->slot->end_time,
                ] : null,
            ] : null,
        ];
    }

    private function serializePatientConsultation(?Consultation $consultation): ?array
    {
        if (! $consultation) {
            return null;
        }

        $status = $consultation->request_status;
        $consultationSession = $consultation->consultationSession;

        $consultationType = $consultation->type === 'follow_up' ? 'follow_up' : 'general';
        $consultationTypeLabel = $consultation->type === 'follow_up' ? 'Follow-up' : 'General';

        return [
            'request_id' => $consultation->request_id,
            'show' => true,
            'show_messaging' => in_array($status, ['active', 'completed'], true) && $consultationSession,
            'details_url' => route('consultations.show', $consultation),
            'consultation_type' => $consultationType,
            'consultation_type_label' => $consultationTypeLabel,
            'concern_category' => $consultation->concern_category,
            'summary' => $this->getConsultationSummary($consultation) ?: 'No symptoms recorded',
            'request_status' => $status,
            'submitted_at' => optional($consultation->submitted_at)->format('M d, Y'),
            'status_badge_class' => $this->getPatientStatusBadgeClass($status),
            'status_label' => ucfirst($status),
            'session' => $consultationSession ? [
                'id' => $consultationSession->id,
                'consultation_status' => $consultationSession->consultation_status,
                'scheduled_slot' => $consultationSession->slot ? [
                    'slot_date' => $consultationSession->slot->slot_date?->format('M d, Y') ?? (string) $consultationSession->slot->slot_date,
                    'start_time' => $consultationSession->slot->start_time,
                    'end_time' => $consultationSession->slot->end_time,
                ] : null,
                'has_clinical_documentation' => $consultationSession->hasClinicalDocumentation(),
                'clinical_badge_class' => $consultationSession->hasClinicalDocumentation() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700',
                'clinical_label' => $consultationSession->hasClinicalDocumentation() ? __('Assessment ready') : __('Assessment pending'),
                'has_prescription' => $consultationSession->hasPrescription(),
                'prescription_badge_class' => $consultationSession->hasPrescription() ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600',
                'prescription_label' => $consultationSession->hasPrescription() ? __('Prescription uploaded') : __('No prescription'),
            ] : null,
        ];
    }

    private function getPatientStatusBadgeClass(string $status): string
    {
        $statusClasses = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold ';

        if (in_array($status, ['rejected', 'cancelled'], true)) {
            return $statusClasses.'bg-red-100 text-red-700';
        }

        if ($status === 'completed') {
            return $statusClasses.'bg-emerald-100 text-emerald-700';
        }

        if (in_array($status, ['pending', 'assigned'], true)) {
            return $statusClasses.'bg-yellow-100 text-yellow-700';
        }

        if ($status === 'scheduled') {
            return $statusClasses.'bg-indigo-100 text-indigo-700';
        }

        if ($status === 'active') {
            return $statusClasses.'bg-blue-100 text-blue-700';
        }

        return $statusClasses.'bg-slate-100 text-slate-700';
    }

    /**
     * Strips characters a filesystem/browser download would reject
     * (/ : \ * ? " < > |) from an otherwise-readable export report name —
     * e.g. "Admin Pedro Reyes Last 30 Days Report" stays exactly as-is,
     * spaces included, since only these nine characters are actually unsafe
     * in a filename.
     */
    private function sanitizeExportFilename(string $name): string
    {
        return str_replace(['/', ':', '\\', '*', '?', '"', '<', '>', '|'], '-', $name);
    }
}
