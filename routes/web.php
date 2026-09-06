<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\ConsultationMessageController;
use App\Http\Controllers\ConsultationVideoController;
use App\Http\Controllers\FollowUpRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PresenceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// --- EVERYTHING INSIDE THIS BLOCK REQUIRES LOGIN ---
Route::middleware(['auth', 'verified'])->group(function () {
    
    // Presence heartbeat
    Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat'])
        ->name('presence.heartbeat');

    // In-app notifications
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/all', [NotificationController::class, 'all'])
        ->name('notifications.all');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('notifications.unread_count');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read_all');

    // 1. Role-based universal dashboard entry point
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');
    Route::get('/dashboard/active-consultation', [DashboardController::class, 'activeConsultation'])
        ->name('dashboard.active_consultation');

    Route::get('/admin/dashboard/export', [DashboardController::class, 'adminDashboardExport'])
        ->name('admin.dashboard.export');

    // Placed in this auth+verified group deliberately, unlike the weaker
    // auth-only /consultations/history route below — an export is a
    // deliberate download action and gets the stronger middleware even
    // though its HTML sibling currently does not (Phase 6).
    Route::get('/consultations/history/export', [ConsultationController::class, 'historyExport'])
        ->name('consultations.history.export');

    Route::get('/admin/users', [\App\Http\Controllers\Admin\UserManagementController::class, 'index'])
        ->name('admin.users.index');
    Route::get('/admin/users/create', [\App\Http\Controllers\Admin\UserManagementController::class, 'create'])
        ->name('admin.users.create');
    Route::post('/admin/users', [\App\Http\Controllers\Admin\UserManagementController::class, 'store'])
        ->name('admin.users.store');
    Route::get('/admin/users/{user}/edit', [\App\Http\Controllers\Admin\UserManagementController::class, 'edit'])
        ->name('admin.users.edit');
    Route::put('/admin/users/{user}', [\App\Http\Controllers\Admin\UserManagementController::class, 'update'])
        ->name('admin.users.update');
    // Admin-only invitation recovery. Throttled to match the other authenticated
    // POST endpoints in this file: generous enough to invite a batch of staff in
    // one sitting, tight enough to bound mailbox spam if the session is misused.
    Route::post('/admin/users/{user}/resend-invitation', [\App\Http\Controllers\Admin\UserManagementController::class, 'resendInvitation'])
        ->middleware('throttle:30,1')
        ->name('admin.users.resend_invitation');

    // 2. Safe placement for your new consultation page
    Route::get('/newconsultation', [DashboardController::class, 'newconsultation'])
        ->name('newconsultation');

    Route::get('/follow-up-list', [FollowUpRequestController::class, 'index'])
        ->name('patient.follow_up_list');
    Route::post('/consultation-sessions/{session}/follow-up-requests', [FollowUpRequestController::class, 'store'])
        ->name('patient.follow_up_requests.store');
    Route::post('/follow-up-requests/{followUpRequest}/cancel', [FollowUpRequestController::class, 'cancel'])
        ->name('patient.follow_up_requests.cancel');

    // Nurse-specific navigation pages
    Route::prefix('nurses/{nurse}')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\NurseController::class, 'dashboard'])
            ->name('nurse.dashboard');
        Route::get('/dashboard/export', [\App\Http\Controllers\NurseController::class, 'dashboardExport'])
            ->name('nurse.dashboard.export');

        Route::get('/consultation-inbox', [\App\Http\Controllers\NurseController::class, 'consultationInbox'])
            ->name('nurse.consultation_inbox');
        Route::get('/consultation-inbox/refresh', [\App\Http\Controllers\NurseController::class, 'consultationInboxRefresh'])
            ->name('nurse.consultation_inbox.refresh');

        Route::get('/follow-up-requests', [\App\Http\Controllers\NurseController::class, 'followUpRequests'])
            ->name('nurse.follow_up_requests');
        Route::post('/follow-up-requests/{followUpRequest}/forward', [\App\Http\Controllers\NurseController::class, 'forwardFollowUpRequest'])
            ->name('nurse.follow_up_requests.forward');
        Route::post('/follow-up-requests/{followUpRequest}/reject', [\App\Http\Controllers\NurseController::class, 'rejectFollowUpRequest'])
            ->name('nurse.follow_up_requests.reject');

        Route::get('/consultation-history', [\App\Http\Controllers\NurseController::class, 'consultationHistory'])
            ->name('nurse.consultation_history');
        Route::get('/consultation-history/export', [\App\Http\Controllers\NurseController::class, 'consultationHistoryExport'])
            ->name('nurse.consultation_history.export');
    });

    //Physician-specific navigation pages
    Route::prefix('physicians/{physician}')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\PhysicianController::class, 'dashboard'])
            ->name('physician.dashboard');
        Route::get('/dashboard/export', [\App\Http\Controllers\PhysicianController::class, 'dashboardExport'])
            ->name('physician.dashboard.export');
        Route::get('/consultation-inbox', [\App\Http\Controllers\PhysicianController::class, 'consultationInbox'])
            ->name('physician.consultation_inbox');
        Route::get('/consultation-inbox/refresh', [\App\Http\Controllers\PhysicianController::class, 'consultationInboxRefresh'])
            ->name('physician.consultation_inbox.refresh');
        Route::post('/consultations/{consultation}/approve-reviewed', [\App\Http\Controllers\PhysicianController::class, 'approveReviewedConsultation'])
            ->name('physician.consultations.approve_reviewed');
        Route::post('/consultations/{consultation}/reject-reviewed', [\App\Http\Controllers\PhysicianController::class, 'rejectReviewedConsultation'])
            ->name('physician.consultations.reject_reviewed');
        Route::post('/consultations/{consultation}/start', [\App\Http\Controllers\PhysicianController::class, 'startConsultation'])
            ->name('physician.consultations.start');
        Route::post('/consultations/{consultation}/take-over', [\App\Http\Controllers\PhysicianController::class, 'takeOverConsultation'])
            ->name('physician.consultations.take_over');
        Route::get('/consultations/{consultation}/available-slots', [\App\Http\Controllers\PhysicianController::class, 'availableScheduleSlotsForConsultation'])
            ->name('physician.consultations.available_slots');
        Route::post('/consultations/{consultation}/schedule', [\App\Http\Controllers\PhysicianController::class, 'scheduleConsultation'])
            ->name('physician.consultations.schedule');
        Route::get('/follow-up-requests', [\App\Http\Controllers\PhysicianController::class, 'followUpRequests'])
            ->name('physician.follow_up_requests');
        Route::get('/follow-up-requests/{followUpRequest}/available-slots', [\App\Http\Controllers\PhysicianController::class, 'availableSlotsForFollowUpRequest'])
            ->name('physician.follow_up_requests.available_slots');
        Route::get('/consultation-sessions/{session}/follow-up/available-slots', [\App\Http\Controllers\PhysicianController::class, 'availableSlotsForPhysicianFollowUp'])
            ->name('physician.follow_up.available_slots');
        Route::post('/follow-up-requests/{followUpRequest}/decide', [\App\Http\Controllers\PhysicianController::class, 'decideFollowUpRequest'])
            ->name('physician.follow_up_requests.decide');
        Route::post('/consultation-sessions/{session}/follow-up', [\App\Http\Controllers\PhysicianController::class, 'createPhysicianFollowUp'])
            ->name('physician.follow_up.create');
        Route::get('/consultation-history', [\App\Http\Controllers\PhysicianController::class, 'consultationHistory'])
            ->name('physician.consultation_history');
        Route::get('/consultation-history/export', [\App\Http\Controllers\PhysicianController::class, 'consultationHistoryExport'])
            ->name('physician.consultation_history.export');
        Route::get('/active_consultation', [\App\Http\Controllers\PhysicianController::class, 'activeConsultations'])
            ->name('physician.active_consultation');
        Route::get('/scheduled_consultation', [\App\Http\Controllers\PhysicianController::class, 'scheduledConsultations'])
            ->name('physician.scheduled_consultation');
        Route::get('/scheduled_consultation/slots', [\App\Http\Controllers\PhysicianController::class, 'scheduledConsultationSlots'])
            ->name('physician.scheduled_consultation.slots');
        Route::post('/scheduled_consultation/generate', [\App\Http\Controllers\PhysicianController::class, 'generateScheduleSlots'])
            ->name('physician.scheduled_consultation.generate');
        Route::post('/scheduled_consultation/save', [\App\Http\Controllers\PhysicianController::class, 'saveScheduleSlots'])
            ->name('physician.scheduled_consultation.save');

        // Consultation Intake — the physician's recurring intake-hours
        // management page. Deliberately not named/routed anywhere near
        // "scheduled_consultation" above, which manages schedule_slots
        // (concrete appointment inventory), a different concept.
        Route::get('/consultation-intake', [\App\Http\Controllers\PhysicianController::class, 'consultationIntake'])
            ->name('physician.consultation_intake');
        Route::post('/consultation-intake/schedules', [\App\Http\Controllers\PhysicianController::class, 'storePhysicianSchedule'])
            ->name('physician.consultation_intake.schedules.store');
        Route::put('/consultation-intake/schedules/{schedule}', [\App\Http\Controllers\PhysicianController::class, 'updatePhysicianSchedule'])
            ->name('physician.consultation_intake.schedules.update');
        Route::delete('/consultation-intake/schedules/{schedule}', [\App\Http\Controllers\PhysicianController::class, 'destroyPhysicianSchedule'])
            ->name('physician.consultation_intake.schedules.destroy');

        // Live intake controls. The {physician} parameter is only there for
        // authorization consistency with the rest of this group — the session
        // acted on is always the authenticated user's, never the one named in
        // the URL. Deliberately CSRF-protected like every other AJAX endpoint
        // in this file, including the heartbeat.
        Route::post('/consultation-intake/open', [\App\Http\Controllers\PhysicianController::class, 'consultationIntakeOpen'])
            ->name('physician.consultation_intake.open');
        Route::post('/consultation-intake/close', [\App\Http\Controllers\PhysicianController::class, 'consultationIntakeClose'])
            ->name('physician.consultation_intake.close');
        Route::post('/consultation-intake/heartbeat', [\App\Http\Controllers\PhysicianController::class, 'consultationIntakeHeartbeat'])
            ->name('physician.consultation_intake.heartbeat');
    });

    // Attachment download for consultations (nurse/physician access validated in controller)
    Route::get('/consultations/{consultation}/attachments/{file}', [\App\Http\Controllers\AttachmentController::class, 'show'])
        ->name('consultation.attachment');

    Route::post('/consultations/{consultation}/reject', [ConsultationController::class, 'rejectionConsultation'])
    ->name('consultations.reject');

    Route::post('/consultations/{consultation}/approve', [ConsultationController::class, 'approveConsultation'])
    ->name('consultations.approve');

    Route::post('/consultations/{consultation}/cancel', [ConsultationController::class, 'cancelConsultation'])
    ->name('consultations.cancel');

    Route::get('/consultation-sessions/{session}/messaging', [ConsultationMessageController::class, 'show'])
        ->name('consultations.messaging.show');
    Route::get('/consultation-sessions/{session}/messages', [ConsultationMessageController::class, 'index'])
        ->name('consultations.messaging.index');
    Route::post('/consultation-sessions/{session}/messages', [ConsultationMessageController::class, 'store'])
        ->name('consultations.messaging.store');
    Route::post('/consultation-sessions/{session}/messages/read', [ConsultationMessageController::class, 'markRead'])
        ->name('consultations.messaging.read');
    Route::post('/consultation-sessions/{session}/clinical-details', [ConsultationMessageController::class, 'updateClinicalDetails'])
        ->name('consultations.messaging.clinical_details.update');
    Route::post('/consultation-sessions/{session}/complete', [ConsultationMessageController::class, 'complete'])
        ->name('consultations.messaging.complete');
    Route::get('/consultation-sessions/unread-counts', [ConsultationMessageController::class, 'unreadCounts'])
        ->name('consultations.messaging.unread_counts');
    Route::post('/consultation-sessions/{session}/typing', [ConsultationMessageController::class, 'typing'])
        ->name('consultations.messaging.typing');
    Route::get('/consultation-sessions/{session}/presence', [ConsultationMessageController::class, 'presence'])
        ->name('consultations.messaging.presence');
    Route::post('/consultation-sessions/{session}/offline', [ConsultationMessageController::class, 'markOffline'])
        ->name('consultations.messaging.offline');
    // Video consultation. Physicians start and end; patients may only join a running room.
    Route::post('/consultation-sessions/{session}/video/start', [ConsultationVideoController::class, 'start'])
        ->middleware('throttle:30,1')
        ->name('consultations.video.start');
    Route::post('/consultation-sessions/{session}/video/join', [ConsultationVideoController::class, 'join'])
        ->middleware('throttle:30,1')
        ->name('consultations.video.join');
    Route::post('/consultation-sessions/{session}/video/end', [ConsultationVideoController::class, 'end'])
        ->name('consultations.video.end');

    Route::get('/consultation-sessions/{session}/prescription/download', [ConsultationMessageController::class, 'downloadPrescription'])
        ->name('consultations.messaging.prescription.download');
    Route::get('/consultation-message-attachments/{attachment}/download', [ConsultationMessageController::class, 'downloadAttachment'])
        ->name('consultations.messaging.attachments.download');

}); // --- MIDDLEWARE GROUP ENDS HERE ---

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/consultations/history', [ConsultationController::class, 'history'])->name('consultations.history');
    Route::get('/consultations/create', [ConsultationController::class, 'create'])->name('consultations.create');
    Route::post('/consultations', [ConsultationController::class, 'store'])->name('consultations.store');
    Route::get('/consultations/{consultation}', [ConsultationController::class, 'show'])->name('consultations.show');
});

require __DIR__.'/auth.php';
