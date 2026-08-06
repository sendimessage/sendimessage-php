<?php

namespace SendImessage;

/** Thrown for any non-2xx API response. */
class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly mixed $body = null,
    ) {
        parent::__construct($message, $status);
    }
}
