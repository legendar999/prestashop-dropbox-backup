<?php

declare(strict_types=1);

namespace Akvabackup\Dropbox;

/**
 * Error while communicating with the Dropbox API.
 * Carries the original HTTP code and response body for diagnostics (never contains tokens:
 * it is only thrown on HTTP errors, where the body is a Dropbox error, not a credential).
 */
class DropboxException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpCode = 0,
        public readonly string $body = ''
    ) {
        parent::__construct($message, $httpCode);
    }
}
