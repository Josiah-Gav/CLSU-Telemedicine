<?php

/*
|--------------------------------------------------------------------------
| Consultation intake
|--------------------------------------------------------------------------
|
| Operational limits for the physician consultation-intake availability
| feature. Both values are here rather than as class constants because an
| administrator may genuinely need to change them without a deployment —
| unlike ConsultationOwnershipService::TAKEOVER_GRACE_MINUTES, which encodes a
| fixed clinical rule.
|
*/

return [

    'intake' => [

        /*
        | Maximum number of consultation requests that may be sitting in the
        | nurse-review queue (request_status = 'pending') before new requests
        | are refused. Counted globally, not per physician: a request has no
        | assigned physician at the moment it is created.
        |
        | Configurable because this ceiling is a staffing decision, not a
        | clinical one — an exam-week surge or an extra nurse on shift changes
        | the right number.
        |
        | Cast to int because env() hands back a string once the key is present
        | in .env, and callers compare this against a query count.
        */
        'queue_limit' => (int) env('CONSULTATION_QUEUE_LIMIT', 20),

        /*
        | How long a physician's open intake session may go without a heartbeat
        | before it is treated as stale and stops keeping consultations open.
        |
        | The browser heartbeat runs every 60 seconds, so 120 lets a physician
        | miss two consecutive beats before intake closes: one dropped request
        | or a briefly throttled background tab never closes the service, while
        | a closed laptop does within roughly two minutes. Configurable because
        | the right margin depends on how reliable the site's network is.
        |
        | Cast to int for the same reason as queue_limit: this value is passed
        | to CarbonImmutable::subSeconds().
        */
        'stale_after_seconds' => (int) env('CONSULTATION_INTAKE_STALE_AFTER', 120),

    ],

];
