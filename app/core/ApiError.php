<?php
declare(strict_types=1);

/** A user-facing error: message is safe to show in the UI. */
final class ApiError extends RuntimeException
{
    public function __construct(string $message, int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
