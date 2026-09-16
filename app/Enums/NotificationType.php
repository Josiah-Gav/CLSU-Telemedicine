<?php

namespace App\Enums;

/**
 * Centralized notification type constants.
 *
 * These values are stored in the `notifications.type` column.
 * Do not scatter raw strings throughout controllers — use these constants.
 */
enum NotificationType: string
{
    // Consultation workflow
    case CONSULTATION_SUBMITTED = 'consultation_submitted';
    case CONSULTATION_REVIEWED = 'consultation_reviewed';
    case CONSULTATION_ASSIGNED = 'consultation_assigned';
    case CONSULTATION_SCHEDULED = 'consultation_scheduled';
    case CONSULTATION_RESCHEDULED = 'consultation_rescheduled';
    case CONSULTATION_STARTING_SOON = 'consultation_starting_soon';
    case CONSULTATION_STARTED = 'consultation_started';
    case CONSULTATION_COMPLETED = 'consultation_completed';
    case CONSULTATION_MISSED = 'consultation_missed';
    case CONSULTATION_CANCELLED = 'consultation_cancelled';

    // Messaging
    case NEW_MESSAGE = 'new_message';
    case NEW_ATTACHMENT = 'new_attachment';

    // Follow-up workflow
    case FOLLOW_UP_SUBMITTED = 'follow_up_submitted';
    case FOLLOW_UP_APPROVED = 'follow_up_approved';
    case FOLLOW_UP_REJECTED = 'follow_up_rejected';
    case FOLLOW_UP_SCHEDULED = 'follow_up_scheduled';
    case FOLLOW_UP_STARTING_SOON = 'follow_up_starting_soon';

    // Operational
    case HIGH_PRIORITY_CONSULTATION = 'high_priority_consultation';
    case PHYSICIAN_REQUEST = 'physician_request';
    case SYSTEM_ALERT = 'system_alert';

    /**
     * Determine whether the given value is a valid notification type.
     */
    public static function isValid(string $type): bool
    {
        return self::tryFrom($type) !== null;
    }
}
