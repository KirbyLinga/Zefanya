<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ForbiddenBuyPageException extends HttpException
{
    /**
     * Thrown when a buyer tries to access a seller-only route.
     *
     * The global 403 handler renders resources/views/errors/403.blade.php,
     * which shows a human-readable "this area is for sellers only" message
     * instead of a raw Laravel 403 page.
     */
    public function __construct(string $message = 'This area is only available to sellers.')
    {
        parent::__construct(403, $message);
    }
}