<?php

namespace App\Exceptions;

use App\Enums\CheckoutErrorCode;

class CheckoutFailedException extends \RuntimeException
{
    public function __construct(
        public readonly CheckoutErrorCode $errorCode,
        public readonly ?string $productCode = null,
    ) {
        parent::__construct($errorCode->value);
    }
}
