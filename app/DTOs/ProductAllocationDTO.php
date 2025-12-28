<?php

namespace App\DTOs;

class ProductAllocationDTO
{
    public function __construct(
        public readonly string $productCode,
        public readonly int $quantity,
        public readonly string $vendorCode,
        public readonly float $unitPrice,
    ) {}

    public function toArray(): array
    {
        return [
            'product_code' => $this->productCode,
            'quantity' => $this->quantity,
            'vendor_code' => $this->vendorCode,
            'unit_price' => $this->unitPrice,
        ];
    }
}
