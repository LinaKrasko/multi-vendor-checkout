<?php

namespace App\Discounts;

use App\Models\QuantityDiscountRule;
use App\DTOs\DiscountInputDTO;

class QuantityThresholdDiscountRule implements DiscountRuleInterface
{
    public function calculate(DiscountInputDTO $input): float
    {
        $enabledRules = QuantityDiscountRule::where('is_enabled', true)
            ->orderBy('min_qty', 'desc')
            ->get();

        if ($enabledRules->isEmpty()) {
            return 0.0;
        }

        $totalDiscount = 0.0;

        foreach ($input->items as $item) {
            foreach ($enabledRules as $rule) {
                if ($item->quantity >= $rule->min_qty) {
                    $itemSubtotal = $item->quantity * $item->unitPrice;
                    $totalDiscount += $itemSubtotal * ($rule->percent / 100);
                    break;
                }
            }
        }

        return $totalDiscount;
    }
}
