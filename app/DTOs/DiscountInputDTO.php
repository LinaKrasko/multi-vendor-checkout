<?php

namespace App\DTOs;

use Illuminate\Support\Collection;

class DiscountInputDTO
{
    /**
     * @param Collection<int, ProductAllocationDTO> $items
     */
    public function __construct(
        public readonly Collection $items
    ) {}
}
