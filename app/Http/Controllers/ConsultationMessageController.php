<?php

namespace App\Http\Controllers;

use App\Models\ConsultationSession;
use App\Models\Message;
use App\Models\User;
use App\Enums\NotificationType;
use App\Notifications\ConsultationCompleted;
use App\Services\ConsultationVideoService;
use App\Services\NotificationService;
use App\Services\MedicalFileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ConsultationMessageController extends Controller
{
    private const TYPING_TTL_SECONDS = 8;

    /**
     * How long a browser may reuse an already-downloaded message attachment.
     * Only applied to attachments, never to prescriptions: a prescription is
     * served from a per-session URL whose file can be replaced in place, so
     * caching it would risk showing a superseded prescription.
     */
    private const ATTACHMENT_CACHE_SECONDS = 3600;

    /** Maximum attachments allowed on a single message. */
    private const MAX_ATTACHMENTS_PER_MESSAGE = 3;

    /** Maximum size, in megabytes, for a non-video attachment (image or document). */
    private const MAX_FILE_SIZE_MB = 10;

    /** Maximum size, in megabytes, for a video attachment. */
    private const MAX_VIDEO_SIZE_MB = 50;

    /** Maximum video attachments allowed on a single message. */
    private const MAX_VIDEOS_PER_MESSAGE = 1;

    /**
     * Extensions accepted for a message attachment. Video is deliberately
     * MP4-only: it is the one format both Cloudinary and every modern browser
     * play back without transcoding, which this application does not do.
     */
    private const ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'mp4'];

    public function __construct(private readonly MedicalFileStorage $medicalFiles) {}

    public function show(ConsultationSession $session)
    {
        $this->authorize('viewMessaging', $session);

        // Messages are not eager-loaded here on purpose: the view never reads
        // $session->messages, it fetches the conversation over AJAX from
        // index() once Alpine boots. Loading them here only paid for rows that
        // were immediately thrown away.
        $session->load([
            'request.patient',
            'request.nurse',
            'physician',
        ]);

        return view('consultations.messaging', [
            'session' => $session,
        ]);
    }

    public function index(ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        $currentUser = Auth::user();
        $this->touchLastSeen((int) $session->id, (int) $currentUser->user_id);

        $messages = $session->messages()
            ->with(['sender', 'attachments'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (Message $message) => $this->serializeMessage($message))
            ->values();

        return response()->json([
            'messages' => $messages,
        ]);
    }

    public function store(Request $request, ConsultationSession $session): JsonResponse
    {
        $this->authorize('sendMessage', $session);

        $validator = Validator::make($request->all(), [
            'message' => 'nullable|string|max:2000',
            'attachments' => 'nullable|array|max:' . self::MAX_ATTACHMENTS_PER_MESSAGE,
            'attachments.*' => ['file', 'mimes:' . implode(',', self::ATTACHMENT_EXTENSIONS)],
        ], [
            'attachments.max' => 'You can attach up to ' . self::MAX_ATTACHMENTS_PER_MESSAGE . ' files per message.',
            'attachments.*.mimes' => 'This file type is not supported.',
        ]);

        // Per-type size caps and the one-video cap can't be expressed as static
        // rule strings (they depend on which file is being looked at), so they
        // are checked here instead of duplicating this into a Rule class for a
        // single call site.
        $validator->after(function ($validator) use ($request) {
            $videoCount = 0;

            foreach ($request->file('attachments', []) as $index => $file) {
                if (!$file->isValid()) {
                    continue;
                }

                $isVideo = strtolower($file->getClientOriginalExtension()) === 'mp4';
                $maxBytes = ($isVideo ? self::MAX_VIDEO_SIZE_MB : self::MAX_FILE_SIZE_MB) * 1024 * 1024;

                if ($isVideo) {
                    $videoCount++;
                }

                if ($file->getSize() > $maxBytes) {
                    $validator->errors()->add(
                        "attachments.$index",
                        $isVideo
                            ? 'This video is too large. Videos must be 50 MB or smaller.'
                            : 'This file is too large. Images and documents must be 10 MB or smaller.'
                    );
                }
            }

            if ($videoCount > self::MAX_VIDEOS_PER_MESSAGE) {
                $validator->errors()->add('attachments', 'You can attach only 1 video per message.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $body = trim((string) ($validated['message'] ?? ''));
        $files = $request->file('attachments', []);

        if ($body === '' && empty($files)) {
            return response()->json([
                'success' => false,
                'message' => 'Provide a message or at least one attachment.',
            ], 422);
        }

        $message = Message::create([
            'consultation_id' => $session->id,
            'sender_id' => Auth::user()->user_id,
            'message' => $body !== '' ? $body : null,
        ]);

        foreach ($files as $file) {
            // Cloudinary (authenticated delivery) with a private-disk fallback,
            // both owned by MedicalFileStorage. What is stored is a reference,
            // never a URL, so downloadAttachment() below stays the only way to
            // reach the bytes whichever backend actually took the file.
            $storedPath = $this->medicalFiles->store(
                $file,
                'message_attachments',
                'message-attachments/' . $session->id
            );

            $message->attachments()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $storedPath,
                'mime_type' => $file->getClientMimeType() ?? 'application/octet-stream',
                'file_size' => $file->getSize() ?? 0,
            ]);
        }

        $this->setTyping((int) $session->id, (int) Auth::user()->user_id, false);
        $this->touchLastSeen((int) $session->id, (int) Auth::user()->user_id);

        $this->notifyMessageRecipients($session, $body !== '', !empty($files));

        // The sender is already known, so it is set rather than re-queried;
        // attachments must be re-read because the relation was resolved before
        // the rows above were created.
        $message->setRelation('sender', Auth::user());
        $message->load('attachments');

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully.',
            // Returned under its own key so the existing string 'message' key
            // keeps its meaning for the error path the frontend already reads.
            // It lets the client show the sent message without refetching the
            // whole conversation; polling still reconciles from index().
            'created_message' => $this->serializeMessage($message),
        ]);
    }

    public function updateClinicalDetails(Request $request, ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        abort_if($session->consultation_status !== 'active', Response::HTTP_FORBIDDEN, 'Clinical details can only be updated while the consultation is active.');

        abort_unless(
            Auth::user()->role === 'physician' && (int) $session->physician_id === (int) Auth::user()->user_id,
            403,
            'Only the assigned physician can update clinical details.'
        );

        $validated = $request->validate([
            'assessment' => 'nullable|string|max:10000',
            'plan' => 'nullable|string|max:10000',
            'recommendations' => 'nullable|string|max:10000',
            'diagnosis' => 'nullable|string|max:255',
            'prescription' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
            'remove_prescription' => 'nullable|boolean',
        ]);

        $session->fill([
            'assessment' => $validated['assessment'] ?? null,
            'plan' => $validated['plan'] ?? null,
            'recommendations' => $validated['recommendations'] ?? null,
            'diagnosis' => $validated['diagnosis'] ?? null,
        ]);

        $removePrescription = (bool) ($validated['remove_prescription'] ?? false);

        if ($removePrescription && !$request->hasFile('prescription')) {
            $this->deletePrescriptionFile($session);
            $session->forceFill([
                'prescription_file_name' => null,
                'prescription_file_path' => null,
                'prescription_mime_type' => null,
                'prescription_file_size' => null,
            ]);
        }

        if ($request->hasFile('prescription')) {
            $file = $request->file('prescription');

            // Same storage contract as a message attachment, in its own
            // Cloudinary folder and its own directory on the private disk.
            $storedPath = $this->medicalFiles->store(
                $file,
                'consultation_prescriptions',
                'consultation-prescriptions/' . $session->id
            );

            $this->deletePrescriptionFile($session);

            $session->forceFill([
                'prescription_file_name' => $file->getClientOriginalName(),
                'prescription_file_path' => $storedPath,
                'prescription_mime_type' => $file->getClientMimeType() ?? 'application/octet-stream',
                'prescription_file_size' => $file->getSize() ?? 0,
            ]);
        }

        $session->save();

        return response()->json([
            'success' => true,
            'message' => 'Clinical details updated successfully.',
            'clinical_details' => $this->buildClinicalDetailsPayload($session->fresh()),
        ]);
    }

    public function complete(ConsultationSession $session, ConsultationVideoService $videoSessions): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        abort_unless(
            Auth::user()->role === 'physician' && (int) $session->physician_id === (int) Auth::user()->user_id,
            403,
            'Only the assigned physician can complete this consultation.'
        );

        if ($session->consultation_status === 'completed' && optional($session->request)->request_status === 'completed') {
            return response()->json([
                'success' => true,
                'message' => 'Consultation is already completed.',
                'session_status' => $session->consultation_status,
                'request_status' => optional($session->request)->request_status,
                'completed_at' => optional($session->completed_at)?->toIso8601String(),
            ]);
        }

        abort_if($session->consultation_status !== 'active', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only active consultations can be completed.');

        $consultationRequest = $session->request;
        abort_unless($consultationRequest, 404);

        DB::transaction(function () use ($session, $videoSessions) {
            $lockedSession = ConsultationSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            abort_unless($lockedSession, 404);

            $lockedRequest = $lockedSession->request()->lockForUpdate()->first();
            abort_unless($lockedRequest, 404);

            $lockedSession->forceFill([
                'consultation_status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $lockedRequest->update([
                'request_status' => 'completed',
            ]);

            if ($lockedSession->slot_id) {
                $lockedSlot = $lockedSession->slot()->lockForUpdate()->first();

                if ($lockedSlot && in_array($lockedSlot->status, ['booked', 'missed'], true)) {
                    $lockedSlot->update([
                        'status' => 'completed',
                    ]);
                }
            }

            // Close any running video call last, inside this same transaction, so the
            // room can never outlive the consultation and a rollback leaves it open.
            // Only rows with a null ended_at are touched, so historical sessions keep
            // their original timestamp.
            $videoSessions->end($lockedSession);
        });

        $session->refresh();
        $consultationRequest = $session->request;

        if ($consultationRequest) {
            NotificationService::sendUnique(
                $consultationRequest->patient_id,
                NotificationType::CONSULTATION_COMPLETED,
                'Consultation Completed',
                'Your consultation has been completed. You can view the summary and prescription in your consultation history.',
                [
                    'consultation_id' => $consultationRequest->request_id,
                    'request_id' => $consultationRequest->request_id,
                    'session_id' => $session->id,
                ]
            );

            if ($consultationRequest->patient) {
                try {
                    $consultationRequest->patient->notify(new ConsultationCompleted($consultationRequest));
                } catch (Throwable $exception) {
                    Log::error('Consultation completed email could not be sent.', [
                        'request_id' => $consultationRequest->request_id,
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Consultation completed successfully.',
            'session_status' => $session->consultation_status,
            'request_status' => $consultationRequest->request_status,
            'completed_at' => optional($session->completed_at)?->toIso8601String(),
        ]);
    }

    public function markRead(ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        $currentUserId = (int) Auth::user()->user_id;
        $this->touchLastSeen((int) $session->id, $currentUserId);

        $session->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $currentUserId)
            ->update([
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Unread message counts for the consultations the caller is a party to.
     *
     * Only a patient and the assigned physician are ever parties to a
     * consultation conversation — ConsultationSessionPolicy::viewMessaging
     * admits nobody else, and only the two views that belong to those roles
     * (patient/dashboard, physician/active_consultation) call this endpoint.
     *
     * The early return is load-bearing, not defensive tidying. The role
     * conditions below are added inside a where() closure, so for any other
     * role that closure contributed no condition at all and the query matched
     * EVERY active session in the system — handing a nurse or an admin a
     * per-session unread count for conversations they cannot open and are not
     * part of. Counts are metadata rather than message content, but they still
     * disclose that a given session exists and how much traffic it carries.
     */
    public function unreadCounts(): JsonResponse
    {
        $currentUser = Auth::user();
        $currentUserId = (int) $currentUser->user_id;

        if (! in_array($currentUser->role, ['patient', 'physician'], true)) {
            return response()->json([
                'counts' => [],
                'total_unread' => 0,
            ]);
        }

        $sessionIds = ConsultationSession::query()
            ->where('consultation_status', 'active')
            ->where(function ($query) use ($currentUser, $currentUserId) {
                if ($currentUser->role === 'patient') {
                    $query->whereHas('request', function ($requestQuery) use ($currentUserId) {
                        $requestQuery
                            ->where('patient_id', $currentUserId)
                            ->where('request_status', 'active');
                    });
                }

                if ($currentUser->role === 'physician') {
                    $query
                        ->where('physician_id', $currentUserId)
                        ->whereHas('request', function ($requestQuery) {
                            $requestQuery->where('request_status', 'active');
                        });
                }
            })
            ->pluck('id')
            ->all();

        if (empty($sessionIds)) {
            return response()->json([
                'counts' => [],
                'total_unread' => 0,
            ]);
        }

        $counts = Message::query()
            ->whereIn('consultation_id', $sessionIds)
            ->whereNull('read_at')
            ->where('sender_id', '!=', $currentUserId)
            ->selectRaw('consultation_id, COUNT(*) as unread_count')
            ->groupBy('consultation_id')
            ->pluck('unread_count', 'consultation_id');

        $normalizedCounts = [];
        $totalUnread = 0;

        foreach ($sessionIds as $sessionId) {
            $count = (int) ($counts[$sessionId] ?? 0);
            $normalizedCounts[(string) $sessionId] = $count;
            $totalUnread += $count;
        }

        return response()->json([
            'counts' => $normalizedCounts,
            'total_unread' => $totalUnread,
        ]);
    }

    public function typing(Request $request, ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        $validated = $request->validate([
            'is_typing' => 'required|boolean',
        ]);

        $userId = (int) Auth::user()->user_id;
        $isTyping = (bool) $validated['is_typing'];

        $this->setTyping((int) $session->id, $userId, $isTyping);
        $this->touchLastSeen((int) $session->id, $userId);

        return response()->json([
            'success' => true,
        ]);
    }

    public function presence(ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        $currentUser = Auth::user();
        $currentUserId = (int) $currentUser->user_id;
        $this->touchLastSeen((int) $session->id, $currentUserId);

        $session->loadMissing(['request.patient', 'physician']);

        $peerUser = null;
        if ($currentUser->role === 'patient') {
            $peerUser = $session->physician;
        } elseif ($currentUser->role === 'physician') {
            $peerUser = optional($session->request)->patient;
        }

        $peerUserId = $peerUser ? (int) $peerUser->user_id : null;
        $peerName = trim((optional($peerUser)->first_name ?? '') . ' ' . (optional($peerUser)->last_name ?? ''));
        $peerIsTyping = $peerUserId
            ? Cache::has($this->typingKey((int) $session->id, $peerUserId))
            : false;
        $peerLastSeen = $peerUserId
            ? Cache::get($this->lastSeenKey((int) $session->id, $peerUserId))
            : null;
        $peerIsOnline = $peerUser
            && $peerUser->online_status === 'online'
            && $peerUser->last_seen_at
            && $peerUser->last_seen_at->gt(now()->subMinutes(2));

        return response()->json([
            'peer' => [
                'user_id' => $peerUserId,
                'name' => $peerName !== '' ? $peerName : null,
                'is_typing' => $peerIsTyping,
                'is_online' => (bool) $peerIsOnline,
                'last_seen_at' => $peerLastSeen,
            ],
            'video' => [
                // Deliberately just a boolean: no room_name, jwt, domain, or any other
                // Jitsi identifier belongs on a passive polling endpoint. Those are only
                // ever issued by the authorized POST /video/join request. Gated on
                // consultation_status === 'active' as well as row existence, so a
                // completed consultation reports false even if a stale open row exists.
                'active' => $session->consultation_status === 'active'
                    && $session->activeVideoSession()->exists(),
            ],
        ]);
    }

    public function markOffline(ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        User::where('user_id', (int) Auth::user()->user_id)
            ->update([
                'online_status' => 'offline',
            ]);

        return response()->json([
            'success' => true,
        ]);
    }

    public function downloadPrescription(ConsultationSession $session)
    {
        $this->authorize('viewMessaging', $session);

        abort_unless($session->prescription_file_path, 404);

        // Authorization above has already run; only then is the file resolved.
        // A Cloudinary-held prescription becomes a signed URL valid for minutes,
        // a local one is streamed off the private disk — neither produces a
        // durable URL to the file itself.
        return $this->medicalFiles->response(
            $session->prescription_file_path,
            $session->prescription_file_name ?? 'prescription'
        );
    }

    public function downloadAttachment(\App\Models\MessageAttachment $attachment)
    {
        $message = $attachment->message;
        $session = optional($message)->consultation;

        abort_unless($session, 404);
        $this->authorize('viewMessaging', $session);

        // A stored attachment is immutable: replacing a file means a new row and
        // therefore a new URL, so the bytes behind this URL never change and the
        // browser can safely reuse them instead of re-downloading on every render.
        // Deliberately 'private', never 'public': this is patient data and must
        // not sit in a shared or proxy cache. Authorization above still runs on
        // every request that actually reaches the server, and the window is kept
        // short so a revoked viewer's own cached copy expires quickly. The header
        // applies to the locally-served case; a Cloudinary reference is answered
        // with a redirect to a signed URL that expires on its own.
        //
        // Inline rather than attachment, which is what this action has always
        // done for its Cloudinary branch: the messaging view renders image
        // attachments straight into an <img> preview from this same URL.
        return $this->medicalFiles->response($attachment->file_path, $attachment->file_name, false, [
            'Cache-Control' => 'private, max-age=' . self::ATTACHMENT_CACHE_SECONDS,
        ]);
    }

    /**
     * The single shape a message takes on the wire. index() and store() both
     * use it so a message the client appends after sending is byte-identical
     * to the same message when polling later refetches it.
     */
    private function serializeMessage(Message $message): array
    {
        return [
            'message_id' => $message->message_id,
            'sender_id' => $message->sender_id,
            'sender_name' => trim((optional($message->sender)->first_name ?? '') . ' ' . (optional($message->sender)->last_name ?? '')),
            'message' => $message->message,
            'read_at' => optional($message->read_at)?->toIso8601String(),
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'attachments' => $message->attachments->map(function ($attachment) {
                return [
                    'attachment_id' => $attachment->attachment_id,
                    'file_name' => $attachment->file_name,
                    'mime_type' => $attachment->mime_type,
                    'file_size' => $attachment->file_size,
                    'download_url' => route('consultations.messaging.attachments.download', $attachment),
                ];
            })->values(),
        ];
    }

    /**
     * Notify the other participant in a consultation session when a message
     * or attachment is sent.
     */
    private function notifyMessageRecipients(ConsultationSession $session, bool $hasMessage, bool $hasAttachments): void
    {
        if (!$hasMessage && !$hasAttachments) {
            return;
        }

        $session->loadMissing(['request.patient', 'physician']);

        $currentUser = Auth::user();
        $currentUserId = (int) $currentUser->user_id;

        $recipientUser = null;
        if ($currentUser->role === 'patient') {
            $recipientUser = $session->physician;
        } elseif ($currentUser->role === 'physician') {
            $recipientUser = optional($session->request)->patient;
        }

        if (!$recipientUser) {
            return;
        }

        $recipientId = (int) $recipientUser->user_id;

        if ($hasMessage) {
            NotificationService::send(
                $recipientId,
                NotificationType::NEW_MESSAGE,
                'New Message',
                'You received a new message for consultation #' . $session->request_id . '.',
                [
                    'consultation_id' => $session->request_id,
                    'request_id' => $session->request_id,
                    'session_id' => $session->id,
                ]
            );
        }

        if ($hasAttachments) {
            NotificationService::send(
                $recipientId,
                NotificationType::NEW_ATTACHMENT,
                'New Attachment',
                'A new attachment was uploaded to consultation #' . $session->request_id . '.',
                [
                    'consultation_id' => $session->request_id,
                    'request_id' => $session->request_id,
                    'session_id' => $session->id,
                ]
            );
        }
    }

    private function setTyping(int $sessionId, int $userId, bool $isTyping): void
    {
        $cacheKey = $this->typingKey($sessionId, $userId);

        if ($isTyping) {
            Cache::put($cacheKey, true, now()->addSeconds(self::TYPING_TTL_SECONDS));
            return;
        }

        Cache::forget($cacheKey);
    }

    private function touchLastSeen(int $sessionId, int $userId): void
    {
        Cache::put($this->lastSeenKey($sessionId, $userId), now()->toIso8601String(), now()->addHours(24));

        User::where('user_id', $userId)
            ->update([
                'online_status' => 'online',
                'last_seen_at' => now(),
            ]);
    }

    private function buildClinicalDetailsPayload(ConsultationSession $session): array
    {
        return [
            'assessment' => $session->assessment,
            'plan' => $session->plan,
            'recommendations' => $session->recommendations,
            'diagnosis' => $session->diagnosis,
            'status' => $session->consultation_status,
            'completed_at' => optional($session->completed_at)?->toIso8601String(),
            'prescription' => [
                'file_name' => $session->prescription_file_name,
                'file_size' => $session->prescription_file_size,
                'download_url' => $session->prescription_file_path
                    ? route('consultations.messaging.prescription.download', $session)
                    : null,
            ],
        ];
    }

    private function deletePrescriptionFile(ConsultationSession $session): void
    {
        $this->medicalFiles->delete($session->prescription_file_path);
    }

    private function typingKey(int $sessionId, int $userId): string
    {
        return 'consultation:' . $sessionId . ':typing:' . $userId;
    }

    private function lastSeenKey(int $sessionId, int $userId): string
    {
        return 'consultation:' . $sessionId . ':last_seen:' . $userId;
    }
}
