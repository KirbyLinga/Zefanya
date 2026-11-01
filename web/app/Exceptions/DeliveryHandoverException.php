<?php

namespace App\Exceptions;

use RuntimeException;

class DeliveryHandoverException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 422,
    ) {
        parent::__construct($message);
    }
}
