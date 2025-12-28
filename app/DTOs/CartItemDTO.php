<?php

namespace App\DTOs;

class CartItemDTO
{
    public function __construct(
        public readonly string $productCode,
        public readonly int $quantity
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['product_code'],
            (int)$data['quantity']
        );
    }
}
