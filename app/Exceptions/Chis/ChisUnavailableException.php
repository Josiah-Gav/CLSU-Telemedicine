<?php

namespace App\Exceptions\Chis;

use RuntimeException;

/**
 * A TRANSIENT CHIS failure: not configured, timeout, connection error, 429,
 * 5xx, or an unreadable success body. Safe to retry later.
 *
 * The message never contains a request/response body, the token, or patient
 * data, and no lower-level exception is chained (a connection error's message
 * includes the URL, which carries the CLSU ID).
 */
class ChisUnavailableException extends RuntimeException
{
    /**
     * @param  string  $reason  short code-like reason, safe to store:
     *                          not_configured, connection_failed, http_<status>, invalid_response
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
