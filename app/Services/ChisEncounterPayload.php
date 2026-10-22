<?php

namespace App\Services;

use App\Exceptions\Chis\InvalidChisPayloadException;
use App\Models\ConsultationSession;
use Carbon\CarbonInterface;

/**
 * Builds the CHIS encounter payload (schema 1.0) for a completed consultation.
 *
 * Privacy (RA 10173): only what the patient consented to is included. Never
 * chat messages, attachments, the prescription file/path/URL, priority, the
 * nurse, email, phone, or the Telemed patient user id.
 *
 * Validated locally against the contract limits; anything invalid throws
 * InvalidChisPayloadException rather than being truncated.
 */
class ChisEncounterPayload
{
    /** Telemed's 1-4 severity scale (see formatSeverityLabel in the nurse/physician views). */
    public const SEVERITY_LABELS = [1 => 'very_mild', 2 => 'mild', 3 => 'moderate', 4 => 'severe'];

    /** Contract max lengths (simulator commit 7f2aaf1, matched to Telemed's own input limits). */
    private const LIMITS = [
        'clsu_id' => 50,
        'patient_name' => 255,
        'physician_name' => 255,
        'specialization' => 100,
        'concern_category' => 100,
        'symptom_name' => 255,
        'additional_information' => 1000,
        'clinical' => 10000,
        'prescription_file_name' => 255,
        'prescription_mime_type' => 100,
    ];

    /**
     * @throws InvalidChisPayloadException
     */
    public static function build(ConsultationSession $session): array
    {
        $session->loadMissing('request.patient', 'physician');
        $request = $session->request;
        $patient = $request?->patient;
        $physician = $session->physician;

        self::require($request && $patient, 'no_request_or_patient');
        self::require($physician !== null, 'no_physician');
        self::require($patient->chis_consent_at !== null, 'no_consent');
        self::require($session->completed_at !== null, 'not_completed');

        $isFollowUp = $request->type === 'follow_up';
        self::require(! $isFollowUp || $request->parent_consultation_id !== null, 'follow_up_without_parent');

        $symptoms = self::symptoms($request->symptoms_desc);
        self::require($symptoms !== [], 'no_valid_symptoms');

        $attached = $session->hasPrescription();

        $payload = [
            'schema_version' => '1.0',
            'telemed_encounter_id' => $session->id,
            'telemed_request_id' => $request->request_id,
            'consent' => [
                'data_sharing_consented_at' => self::iso($patient->chis_consent_at),
            ],
            'patient' => [
                'clsu_id' => self::text($patient->clsu_id),
                'first_name' => self::text($patient->first_name),
                'last_name' => self::text($patient->last_name),
            ],
            'physician' => [
                'telemed_user_id' => $session->physician_id,
                'name' => self::text(trim($physician->first_name.' '.$physician->last_name)),
                'specialization' => self::text($physician->specialization),
            ],
            'encounter' => [
                'type' => $isFollowUp ? 'follow_up' : 'general',
                'parent_telemed_encounter_id' => $isFollowUp ? $request->parent_consultation_id : null,
                'mode' => 'teleconsultation',
                'started_at' => self::iso($session->started_at),
                'completed_at' => self::iso($session->completed_at),
            ],
            'presenting_complaint' => [
                'concern_category' => self::text($request->concern_category),
                'symptoms' => $symptoms,
                'additional_information' => self::text($request->additional_information),
            ],
            'clinical' => [
                'diagnosis' => self::text($session->diagnosis),
                'assessment' => self::text($session->assessment),
                'plan' => self::text($session->plan),
                'recommendations' => self::text($session->recommendations),
            ],
            'follow_up' => [
                'required' => (bool) $session->follow_up_required,
                'date' => $session->follow_up_date?->toDateString(),
            ],
            'prescription' => [
                'attached' => $attached,
                'file_name' => $attached ? self::text($session->prescription_file_name) : null,
                'mime_type' => $attached ? self::text($session->prescription_mime_type) : null,
            ],
        ];

        self::validate($payload);

        return $payload;
    }

    /**
     * Valid symptom entries only: a name (≤255) and a 1-4 severity. Older or
     * malformed rows exist (see SymptomAnalytics), so bad entries are skipped
     * rather than invalidating the whole payload.
     */
    private static function symptoms(mixed $raw): array
    {
        $symptoms = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            $name = is_array($entry) && is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            // "3" and 3 are accepted; "mild", 3.5 and 9 are not.
            $severity = filter_var($entry['severity'] ?? null, FILTER_VALIDATE_INT);

            if ($name === '' || mb_strlen($name) > self::LIMITS['symptom_name'] || ! isset(self::SEVERITY_LABELS[$severity])) {
                continue;
            }

            $symptoms[] = [
                'name' => $name,
                'severity' => $severity,
                'severity_label' => self::SEVERITY_LABELS[$severity],
                'onset_date' => self::onsetDate($entry['date'] ?? null),
                'onset_time' => self::onsetTime($entry['time'] ?? null),
            ];
        }

        return $symptoms;
    }

    private static function validate(array $p): void
    {
        foreach (['clsu_id' => $p['patient']['clsu_id'], 'first_name' => $p['patient']['first_name'], 'last_name' => $p['patient']['last_name'],
            'physician_name' => $p['physician']['name'], 'concern_category' => $p['presenting_complaint']['concern_category']] as $field => $value) {
            self::require($value !== null, "missing_{$field}");
        }

        foreach ($p['clinical'] as $field => $value) {
            self::require($value !== null, "missing_{$field}");
            self::max($value, self::LIMITS['clinical'], $field);
        }

        self::max($p['patient']['clsu_id'], self::LIMITS['clsu_id'], 'clsu_id');
        self::max($p['patient']['first_name'], self::LIMITS['patient_name'], 'first_name');
        self::max($p['patient']['last_name'], self::LIMITS['patient_name'], 'last_name');
        self::max($p['physician']['name'], self::LIMITS['physician_name'], 'physician_name');
        self::max($p['physician']['specialization'], self::LIMITS['specialization'], 'specialization');
        self::max($p['presenting_complaint']['concern_category'], self::LIMITS['concern_category'], 'concern_category');
        self::max($p['presenting_complaint']['additional_information'], self::LIMITS['additional_information'], 'additional_information');
        self::max($p['prescription']['file_name'], self::LIMITS['prescription_file_name'], 'prescription_file_name');
        self::max($p['prescription']['mime_type'], self::LIMITS['prescription_mime_type'], 'prescription_mime_type');
    }

    private static function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new InvalidChisPayloadException($reason);
        }
    }

    private static function max(?string $value, int $limit, string $field): void
    {
        self::require($value === null || mb_strlen($value) <= $limit, "{$field}_too_long");
    }

    /** Trimmed text, or null when blank. */
    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function iso(?CarbonInterface $at): ?string
    {
        return $at?->copy()->timezone(config('app.timezone'))->toIso8601String();
    }

    /** YYYY-MM-DD, or null when blank or not a real calendar date. */
    private static function onsetDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? $m[0]
            : null;
    }

    /** HH:MM (seconds dropped), or null when blank or not a valid time. */
    private static function onsetTime(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', trim($value), $m) && (int) $m[1] < 24 && (int) $m[2] < 60
            ? sprintf('%02d:%s', $m[1], $m[2])
            : null;
    }
}
