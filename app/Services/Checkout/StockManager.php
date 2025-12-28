<?php

namespace App\Services\Checkout;

use Illuminate\Support\Facades\DB;

class StockManager
{
    public function decrementStock(string $vendorCode, string $productCode, int $qty): bool
    {
        $affected = DB::table('vendor_products')
            ->where('vendor_code', $vendorCode)
            ->where('product_code', $productCode)
            ->where('stock', '>=', $qty)
            ->decrement('stock', $qty);

        return $affected > 0;
    }
}
