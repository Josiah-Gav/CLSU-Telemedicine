<?php

namespace App\Exceptions\Chis;

use RuntimeException;

/**
 * The encounter payload fails the CHIS contract locally, so it is never sent.
 * The message is a short reason code (e.g. no_valid_symptoms,
 * assessment_too_long), never data.
 */
class InvalidChisPayloadException extends RuntimeException {}
