<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Services\MedicalFileStorage;
use Illuminate\Support\Facades\Auth;

class AttachmentController extends Controller
{
    public function __construct(private readonly MedicalFileStorage $medicalFiles)
    {
        $this->middleware('auth');
    }

    /**
     * Consultation request statuses that make up the physician consultation
     * inbox's shared triage pool. PhysicianController::getConsultationInboxData
     * deliberately applies no assigned_physician_id filter, so every physician
     * can already see these requests and their attachments in the inbox modal.
     */
    private const PHYSICIAN_POOL_STATUSES = ['reviewed', 'assigned', 'scheduled'];

    /**
     * Serve a consultation attachment to someone allowed to see it.
     *
     * This is now the only way in. The stored values used to be URLs — a public
     * Cloudinary link, or asset('storage/...') pointing at the public disk —
     * which meant the file was fetchable without ever reaching this method.
     * They are MedicalFileStorage references now, so the bytes are behind
     * either an authenticated-delivery signature this action mints or a private
     * disk the web server cannot serve.
     */
    public function show(Consultation $consultation, $file)
    {
        if (! $this->canViewAttachments($consultation)) {
            abort(403, 'Unauthorized access.');
        }

        foreach ($consultation->file_attachments ?? [] as $reference) {
            if ($this->medicalFiles->attachmentKey($reference) !== $file) {
                continue;
            }

            // Inline, not attachment: every view that links here renders the
            // result in an <img> preview.
            return $this->medicalFiles->response($reference, $file, asAttachment: false);
        }

        abort(404);
    }

    /**
     * Nurses keep blanket access (unchanged). A physician gets access only to
     * what their inbox already shows them: a request still in the shared triage
     * pool, or one assigned to them personally at any status. A request that
     * has left the pool and belongs to another physician is off limits.
     *
     * The patient who submitted the request is allowed their own attachments.
     * They previously reached these files by way of the raw public URL embedded
     * in the page; with that URL gone, this route is the only path left, and
     * refusing them here would hide a patient's own uploads from them.
     * Ownership is checked against the request row, so it grants nothing beyond
     * the attachments they themselves submitted.
     */
    private function canViewAttachments(Consultation $consultation): bool
    {
        $user = Auth::user();

        if ($user->role === 'nurse') {
            return true;
        }

        if ($user->role === 'patient') {
            return (int) $consultation->patient_id === (int) $user->user_id;
        }

        if ($user->role !== 'physician') {
            return false;
        }

        return (int) $consultation->assigned_physician_id === (int) $user->user_id
            || in_array($consultation->request_status, self::PHYSICIAN_POOL_STATUSES, true);
    }
}
