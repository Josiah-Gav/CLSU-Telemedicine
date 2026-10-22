<?php

namespace App\Exceptions\Chis;

use RuntimeException;

/**
 * A PERMANENT CHIS failure: any 4xx other than 429 (e.g. 401, 404, 409, 422).
 * Retrying the same request will fail the same way, so record it instead.
 *
 * Carries the HTTP status and the CHIS error `code` (e.g. ENCOUNTER_CONFLICT)
 * for the caller to record. The message never contains a body or patient data.
 */
class ChisRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $chisCode = null,
    ) {
        parent::__construct($message);
    }
}
