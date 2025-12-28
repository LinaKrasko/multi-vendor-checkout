<?php

namespace App\DTOs;

use App\Models\Order;
use App\Enums\CheckoutErrorCode;

class CheckoutResultDTO
{
    private function __construct(
        public readonly bool $success,
        public readonly ?Order $order = null,
        public readonly ?CheckoutErrorCode $errorCode = null,
        public readonly ?string $productCode = null
    ) {}

    public static function success(Order $order): self
    {
        return new self(true, order: $order);
    }

    public static function failure(CheckoutErrorCode $errorCode, ?string $productCode = null): self
    {
        return new self(false, errorCode: $errorCode, productCode: $productCode);
    }
}
