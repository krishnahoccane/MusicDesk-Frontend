<?php

namespace App\Licensing;

use RuntimeException;

/** A licensing rule refusal with a stable machine code (shown to customers, returned by the license server). */
class LicenseException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
