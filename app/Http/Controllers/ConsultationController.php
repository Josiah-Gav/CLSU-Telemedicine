<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Consultation;
use App\Models\SymptomLog; // Double-check that your SymptomLog model exists
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use App\Models\FollowUpRequest;
use App\Enums\NotificationType;
use App\Services\NotificationService;
use App\Services\ConsultationOwnershipService;
use App\Services\PhysicianAvailabilityService;
use App\Services\MedicalFileStorage;
use App\Services\Export\ConsultationHistoryQuery;
use App\Services\Export\ConsultationHistoryRows;
use App\Support\CsvDownload;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\User;

class ConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationOwnershipService $ownershipService,
        private readonly PhysicianAvailabilityService $availabilityService,
        private readonly MedicalFileStorage $medicalFiles,
    ) {
    }

    /**
     * Guard the two nurse-triage actions below (approve/reject).
     *
     * These live on ConsultationController rather than NurseController, so they
     * are not covered by NurseController::authorizeNurse(). Their routes carry
     * no {nurse} parameter either, so there is no route-bound user to match
     * against — the acting nurse is always auth()->id(), which is what both
     * actions already pass to ConsultationOwnershipService.
     *
     * ConsultationOwnershipService is deliberately role-agnostic: it validates
     * workflow state and assignment, never who is asking. That is by design and
     * is not changed here — this is the missing role half, and it has to live at
     * the controller boundary, exactly as FollowUpRequestController does it for
     * the patient-facing follow-up actions.
     */
    private function authorizeNurse(): void
    {
        if (auth()->user()?->role !== 'nurse') {
            abort(403, 'Unauthorized access.');
        }
    }

    /**
     * Display a listing of the consultations.
     */
    public function index()
    {
        // Fetch consultations for the authenticated user
        $consultations = Consultation::where('patient_id', auth()->id())->get();
        return view('consultations.index', compact('consultations'));
    }

    /**
     * Display the patient's consultation history.
     */
    public function history()
    {
        // Filtering and query construction live in ConsultationHistoryQuery so
        // the Phase 6 export can reuse the exact same semantics. Ownership is
        // still supplied by the controller (auth()->id()); the service never
        // touches Auth or authorizes anything.
        $filters = ConsultationHistoryQuery::normalizeFilters(
            request()->query('date_filter', 'all'),
            request()->query('status', 'all'),
            request()->query('consultation_type', 'all'),
        );

        $patientId = (int) auth()->id();

        $consultations = ConsultationHistoryQuery::forPatient($patientId, $filters)->get();

        $rejectedFollowUpRequests = ConsultationHistoryQuery::rejectedFollowUpsForPatient($patientId, $filters)->get();

        // Moved into ConsultationHistoryRows::mergePatientHistoryItems() in
        // Phase 6 so the patient history export can build the identical
        // merged/sorted list from one implementation — see its docblock.
        $historyItems = ConsultationHistoryRows::mergePatientHistoryItems($consultations, $rejectedFollowUpRequests);

        // $filters already holds exactly the previous
        // date_filter/status/consultation_type array, built by
        // normalizeFilters() above, so it is passed straight to the view.
        return view('patient.consultation-history', compact('consultations', 'rejectedFollowUpRequests', 'historyItems', 'filters'));
    }

    /**
     * CSV/PDF export of the patient's own consultation history. Filtering
     * and the merged/sorted historyItems shape are identical to history()
     * above — both call ConsultationHistoryQuery and
     * ConsultationHistoryRows::mergePatientHistoryItems(), so the export can
     * never disagree with what the HTML page currently shows for the same
     * query string.
     *
     * Unlike history(), this action explicitly requires role=patient rather
     * than relying on the implicit patient_id scoping alone — the existing
     * page's scoping already prevents data leakage, but an export is a
     * deliberate download action and gets its own explicit role check.
     */
    public function historyExport()
    {
        if (auth()->user()?->role !== 'patient') {
            abort(403, 'Unauthorized access.');
        }

        $format = (string) request()->query('format', 'csv');

        if (! in_array($format, ['csv', 'pdf'], true)) {
            abort(422, 'Unsupported export format.');
        }

        $filters = ConsultationHistoryQuery::normalizeFilters(
            request()->query('date_filter', 'all'),
            request()->query('status', 'all'),
            request()->query('consultation_type', 'all'),
        );

        $patientId = (int) auth()->id();

        // Same forPatient() query the HTML page uses; chaining extra eager
        // loads here only adds data the export needs to render Assigned
        // Nurse/Physician/Completed At without N+1 — it does not touch
        // filtering, scoping, or ordering.
        $consultations = ConsultationHistoryQuery::forPatient($patientId, $filters)
            ->with(['consultationSession', 'nurse', 'physician'])
            ->get();

        $rejectedFollowUpRequests = ConsultationHistoryQuery::rejectedFollowUpsForPatient($patientId, $filters)->get();

        $historyItems = ConsultationHistoryRows::mergePatientHistoryItems($consultations, $rejectedFollowUpRequests);
        $rows = ConsultationHistoryRows::patientRows($historyItems);

        // The authenticated user IS the owner for a patient's own history
        // export (there's no separate "who ran this" identity here), so the
        // already-resolved $patient supplies both the Owner and Generated By
        // rows — no second lookup needed.
        $patient = auth()->user();
        $generatedBy = trim($patient->first_name.' '.$patient->last_name);
        $timelineLabel = ConsultationHistoryRows::timelineLabel($filters['date_filter'] ?? 'all');

        $title = "Patient {$generatedBy} {$timelineLabel} History Report";
        $meta = array_merge([
            ['Role', 'Patient'],
            ['Owner', $generatedBy],
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
                'headers' => ConsultationHistoryRows::PATIENT_HEADERS,
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
            ConsultationHistoryRows::toCsvRows($title, $meta, ConsultationHistoryRows::PATIENT_HEADERS, $rows),
        );
    }

    /**
     * Show the form for creating a new consultation.
     */
    public function create()
    {
        $patient = auth()->user();

        if ($patient->role !== 'patient') {
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
        // truth store() enforces — this view datum is purely informational and
        // never itself gates the submission.
        $intakeAvailable = $this->availabilityService->isServiceAvailable();

        return view('patient.newconsultation', compact('patient', 'intakeAvailable'));
    }

    /**
     * Store a newly created consultation request in storage (Called on Step 4 submission).
     */
    public function store(Request $request)
    {
        // 1. Enforce one active consultation request per patient
        $existingActiveConsultation = Consultation::where('patient_id', auth()->id())
            ->whereIn('request_status', ['pending', 'reviewed', 'assigned', 'scheduled', 'active'])
            ->where(function ($query) {
                $query->whereDoesntHave('consultationSession')
                    ->orWhereHas('consultationSession', function ($sessionQuery) {
                        $sessionQuery->whereIn('consultation_status', ['scheduled', 'active']);
                    });
            })
            ->exists();

        if ($existingActiveConsultation) {
            return response()->json([
                'success' => false,
                'message' => 'You may only have one active consultation request at a time.',
            ], 422);
        }

        // 1b. Consultation intake gate. The service is the single authority on
        // whether the telemedicine service can take a NEW request right now —
        // it already weighs eligible physicians, their presence and its
        // freshness, an open and non-stale intake session, and the global
        // pending-queue limit. None of those rules are repeated here.
        //
        // Placed after the duplicate check above, and deliberately not before
        // it: a patient who already has a request open would be refused
        // whatever intake was doing, so telling them the service is
        // unavailable would be the less accurate of the two true answers.
        //
        // Placed before validation and the uploads below so a refused request
        // never reaches Cloudinary, never writes a row, and never notifies a
        // nurse. Gating only creation — no existing pending, scheduled, or
        // active consultation is read or touched here.
        //
        // 503 rather than 422: this is a temporary service condition the
        // patient can retry, not a problem with what they submitted. It is
        // also the only 503 store() can return, so it identifies this case on
        // its own. Nothing about any individual physician is disclosed.
        if (! $this->availabilityService->isServiceAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Consultations are currently unavailable. Please try again later.',
            ], 503);
        }

        // 2. Validate the form inputs
        $validated = $request->validate([
            'concern_category' => 'required|string|max:100',
            'symptoms_payload' => 'required|string',
            'online_reason'    => 'required|string|max:1000',
            'additional_notes' => 'nullable|string|max:1000',
            'attachments.*'    => 'nullable|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB Limit
        ]);

        // 3. Decode alpine symptom list tracking payload
        $symptomsData = json_decode($validated['symptoms_payload'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($symptomsData) || count($symptomsData) === 0) {
            return response()->json(['success' => false, 'message' => 'Please provide at least one symptom.'], 422);
        }

        // 3a. Require an explicit severity on every symptom. The severity
        // picker used to initialize each symptom at 3 ("Moderate") the
        // instant it was selected, so a patient who never touched the
        // picker still submitted a severity indistinguishable from a
        // deliberate choice — the same class of gap as the onset date/time
        // check below, and enforced the same way: the client now starts
        // severity unset, and this is what actually stops an unset (or
        // out-of-scale) value from being recorded as data.
        //
        // The 1-4 scale is duplicated from SymptomAnalytics::VALID_SEVERITIES
        // (private to that class) rather than shared, since it appears in
        // exactly these two places; keep both in sync if it ever changes.
        foreach ($symptomsData as $symptom) {
            $severity = is_array($symptom) ? ($symptom['severity'] ?? null) : null;
            if (!is_numeric($severity) || !in_array((int) $severity, [1, 2, 3, 4], true)) {
                return response()->json(['success' => false, 'message' => 'Please select a severity for every symptom.'], 422);
            }
        }

        // 3b. Reject a future onset date/time. The date/time picker only
        // offers past-or-present values, but symptoms_payload is otherwise
        // unvalidated per-entry (see SymptomAnalytics' class docblock, H-4)
        // so a direct POST past the form could still submit one — this is
        // the only check that actually enforces it. Date/time stay optional;
        // only a supplied value is checked.
        foreach ($symptomsData as $symptom) {
            $date = is_array($symptom) ? ($symptom['date'] ?? null) : null;
            if (! $date) {
                continue;
            }

            $time = is_array($symptom) ? ($symptom['time'] ?? null) : null;

            try {
                $onset = \Carbon\Carbon::parse($date . ' ' . ($time ?: '00:00'));
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'One of the symptom onset dates is invalid.'], 422);
            }

            if ($onset->isFuture()) {
                return response()->json(['success' => false, 'message' => 'Symptom onset date and time cannot be in the future.'], 422);
            }
        }

        // 3. Process uploads.
        //
        // These are patient-submitted medical images. They used to be stored as
        // a public Cloudinary URL, or — when Cloudinary threw — on the *public*
        // local disk behind asset('storage/...'), which the web server serves
        // straight off the filesystem with no authorization at all. Both are
        // now handled by MedicalFileStorage: authenticated Cloudinary delivery,
        // falling back to the private disk, and what is persisted is a
        // reference rather than a URL. Nothing here is reachable without going
        // through AttachmentController::show, which authorizes first.
        $uploadedFileReferences = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $uploadedFileReferences[] = $this->medicalFiles->store(
                    $file,
                    'telemed_consultations',
                    'consultation-attachments/' . auth()->id()
                );
            }
        }

        try {
            // 4. Record details using your modified database column structure
            $consultation = Consultation::create([
                'patient_id'              => auth()->id(),
                'assigned_physician_id'   => null,
                'assigned_nurse_id'       => null,
                'concern_category'        => $validated['concern_category'],
                'symptoms_desc'           => $symptomsData,
                'online_reason'           => $validated['online_reason'] ?? null,
                'additional_information'  => $validated['additional_notes'] ?? null,
                // Stored references, not URLs — see MedicalFileStorage.
                'file_attachments'        => !empty($uploadedFileReferences) ? $uploadedFileReferences : null,
                'request_status'          => 'pending',
            ]);

            NotificationService::sendToRole(
                'nurse',
                NotificationType::CONSULTATION_SUBMITTED,
                'New Consultation Request',
                'A new consultation request requires your review.',
                [
                    'consultation_id' => $consultation->request_id,
                    'request_id' => $consultation->request_id,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Consultation request created and backed up to cloud successfully.',
                'data'    => $consultation
            ], 201);

        } catch (\Exception $e) {
            Log::error('Consultation submission failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Server error encountered.'], 500);
        }
    }

    /**
     * Display the details of a consultation.
     */
    public function show(Consultation $consultation)
    {
        abort_unless(Gate::allows('view', $consultation), 403, 'Unauthorized access.');

        $consultation->load(['nurse', 'physician', 'consultationSession.slot', 'parentConsultation.request']);

        return view('patient.consultation-details', compact('consultation'));
    }


    function rejectionConsultation(Request $request, Consultation $consultation)
    {
        $this->authorizeNurse();

        // Validate the rejection reason
        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        try {
            $consultation = $this->ownershipService->rejectByNurse(
                (int) $consultation->request_id,
                (int) auth()->id(),
                (string) $request->input('rejection_reason')
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        NotificationService::send(
            $consultation->patient_id,
            NotificationType::CONSULTATION_REVIEWED,
            'Consultation Request Rejected',
            'Your consultation request was rejected. Reason: ' . $request->input('rejection_reason'),
            [
                'consultation_id' => $consultation->request_id,
                'request_id' => $consultation->request_id,
                'rejected' => true,
            ]
        );

        return response()->json(['success' => true, 'message' => 'Consultation request rejected successfully.']);
    }

    function approveConsultation(Request $request, Consultation $consultation)
    {
        $this->authorizeNurse();

        $validated = $request->validate([
            'priority_level' => 'required|in:High,Normal',
        ]);

        try {
            $consultation = $this->ownershipService->claimByNurse(
                (int) $consultation->request_id,
                (int) auth()->id(),
                (string) $validated['priority_level']
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Notify the patient that their request was reviewed.
        NotificationService::send(
            $consultation->patient_id,
            NotificationType::CONSULTATION_REVIEWED,
            'Consultation Reviewed',
            'Your consultation request has been reviewed by the infirmary staff.',
            [
                'consultation_id' => $consultation->request_id,
                'request_id' => $consultation->request_id,
            ]
        );

        // Notify all physicians that a new consultation is ready for assignment.
        $isHighPriority = strtolower((string) $validated['priority_level']) === 'high';
        NotificationService::sendToRole(
            'physician',
            $isHighPriority ? NotificationType::HIGH_PRIORITY_CONSULTATION : NotificationType::CONSULTATION_ASSIGNED,
            $isHighPriority ? 'High-Priority Consultation' : 'New Consultation Available',
            $isHighPriority
                ? 'A high-priority consultation has been approved and is waiting for a physician.'
                : 'A new consultation has been approved and is waiting for a physician.',
            [
                'consultation_id' => $consultation->request_id,
                'request_id' => $consultation->request_id,
                'priority_level' => $consultation->priority_level,
            ]
        );

        return response()->json(['success' => true, 'message' => 'Consultation request approved successfully.']);
    }

    function cancelConsultation(Request $request, Consultation $consultation)
    {
        // Ensure the consultation belongs to the authenticated user
        if ($consultation->patient_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized action.'], 403);
        }

        try {
            $consultation = $this->ownershipService->cancelByPatient(
                (int) $consultation->request_id,
                (int) auth()->id()
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Notify the assigned nurse (if any) that the patient cancelled the request.
        if ($consultation->assigned_nurse_id) {
            NotificationService::send(
                $consultation->assigned_nurse_id,
                NotificationType::CONSULTATION_CANCELLED,
                'Consultation Cancelled',
                'A patient cancelled their consultation request.',
                [
                    'consultation_id' => $consultation->request_id,
                    'request_id' => $consultation->request_id,
                ]
            );
        }

        return response()->json(['success' => true, 'message' => 'Consultation request cancelled successfully.']);
    }

    /**
     * Strips characters a filesystem/browser download would reject
     * (/ : \ * ? " < > |) from an otherwise-readable export report name —
     * e.g. "Patient Juan Dela Cruz Last 30 Days History Report" stays
     * exactly as-is, spaces included, since only these nine characters are
     * actually unsafe in a filename.
     */
    private function sanitizeExportFilename(string $name): string
    {
        return str_replace(['/', ':', '\\', '*', '?', '"', '<', '>', '|'], '-', $name);
    }

    // You can leave edit, update, and destroy empty or remove them if unused!
}
