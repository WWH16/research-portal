<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The disk that holds project documents can't be reached or refused a write: Google Drive isn't connected,
 * its access was revoked, or Google is down. Pages show the member a plain message instead of an error page.
 */
class DocumentStorageUnavailable extends RuntimeException
{
    public function __construct(string $message = 'Document storage is unavailable.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
