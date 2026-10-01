<?php

namespace App\Http\Controllers;

use RuntimeException;

/**
 * A 409 with extra machine readable fields next to the message, e.g. `retry` or `existingPost`
 */
class ConflictException extends RuntimeException
{
    public function __construct(string $message, public readonly array $extra = [])
    {
        parent::__construct($message, 409);
    }
}
