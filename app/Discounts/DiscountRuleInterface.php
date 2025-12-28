<?php

namespace App\Discounts;

use App\DTOs\DiscountInputDTO;

interface DiscountRuleInterface
{
    public function calculate(DiscountInputDTO $input): float;
}
