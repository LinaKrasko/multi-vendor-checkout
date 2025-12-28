<?php

namespace App\Discounts;

use App\Models\CategoryDiscountRule as CategoryDiscountRuleModel;
use App\Models\VendorProduct;
use App\DTOs\DiscountInputDTO;

class CategoryDiscountRule implements DiscountRuleInterface
{
    public function calculate(DiscountInputDTO $input): float
    {
        $enabledRules = CategoryDiscountRuleModel::where('is_enabled', true)
            ->get()
            ->keyBy('category');

        if ($enabledRules->isEmpty()) {
            return 0.0;
        }

        $totalDiscount = 0.0;

        foreach ($input->items as $item) {
            $productInfo = VendorProduct::where('product_code', $item->productCode)
                ->whereNotNull('category')
                ->first();

            if ($productInfo && isset($enabledRules[$productInfo->category])) {
                $rule = $enabledRules[$productInfo->category];
                $itemTotal = $item->quantity * $item->unitPrice;
                $totalDiscount += $itemTotal * ($rule->percent / 100);
            }
        }

        return round($totalDiscount, 2);
    }
}
