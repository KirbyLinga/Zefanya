<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A cart line cannot be added or changed because its product/variant is no
 * longer for sale. Callers render the message (web JSON `{error}` / API 422).
 */
class CartItemUnavailableException extends RuntimeException {}
