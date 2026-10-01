<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An image URL could not be turned into usable image links. The message is meant for the user.
 */
class ImageProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $actualProvider = null)
    {
        parent::__construct($message);
    }
}
