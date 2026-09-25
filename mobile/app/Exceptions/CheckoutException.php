<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised inside the checkout transaction when the order cannot be placed
 * (stock vanished / went insufficient under lock). Caught by
 * CheckoutController@store, which flashes the message and sends the buyer
 * back to the cart — no partial writes survive the rollback.
 */
class CheckoutException extends RuntimeException {}
