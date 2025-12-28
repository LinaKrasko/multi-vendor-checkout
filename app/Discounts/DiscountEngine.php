<?php

namespace App\Discounts;

use App\DTOs\DiscountInputDTO;

class DiscountEngine
{
    protected array $rules = [];

    public function __construct(array $rules = [])
    {
        foreach ($rules as $rule) {
            $this->addRule($rule);
        }
    }

    public function addRule(DiscountRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    public function calculate(DiscountInputDTO $input): float
    {
        $totalDiscount = 0.0;
        foreach ($this->rules as $rule) {
            $totalDiscount += $rule->calculate($input);
        }

        return round($totalDiscount, 2);
    }
}
