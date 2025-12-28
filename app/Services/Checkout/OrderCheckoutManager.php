<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Models\SubOrder;
use App\Enums\OrderStatus;
use App\Enums\CheckoutErrorCode;
use App\DTOs\CheckoutResultDTO;
use App\DTOs\ProductAllocationDTO;
use App\DTOs\DiscountInputDTO;
use App\Discounts\DiscountEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderCheckoutManager
{
    public function __construct(
        private readonly StockManager $stockManager,
        private readonly DiscountEngine $discountEngine
    ) {}

    public function createFromCart(array $items): CheckoutResultDTO
    {
        return DB::transaction(function () use ($items) {
            $allocations = $this->allocateItemsToVendors($items);

            if ($allocations instanceof CheckoutResultDTO) {
                return $allocations;
            }

            $order = $this->createOrder($allocations);

            $this->createSubOrders($order, $allocations);

            return CheckoutResultDTO::success($order);
        });
    }

    private function allocateItemsToVendors(array $items): array|CheckoutResultDTO
    {
        $allocations = [];

        foreach ($items as $item) {
            $reservation = $this->reserveProduct($item->productCode, $item->quantity);

            if ($reservation instanceof CheckoutErrorCode) {
                return CheckoutResultDTO::failure($reservation, $item->productCode);
            }

            $allocations[] = $reservation;
        }

        return $allocations;
    }

    private function createSubOrders(Order $order, array $allocations): void
    {
        $groupedByVendor = collect($allocations)->groupBy('vendorCode');

        foreach ($groupedByVendor as $vendorCode => $vendorAllocations) {
            $this->createSubOrder($order, (string)$vendorCode, $vendorAllocations->all());
        }
    }

    private function reserveProduct(string $productCode, int $quantity): ProductAllocationDTO|CheckoutErrorCode
    {
        if (!$this->isProductAvailableInCatalog($productCode)) {
            return CheckoutErrorCode::UNKNOWN_PRODUCT;
        }

        $vendors = $this->getVendorsOrderedByPrice($productCode);

        if ($vendors->isEmpty()) {
            return CheckoutErrorCode::NO_OFFERS;
        }

        foreach ($vendors as $vendor) {
            if ($this->stockManager->decrementStock($vendor->vendor_code, $productCode, $quantity)) {
                return new ProductAllocationDTO(
                    productCode: $productCode,
                    quantity: $quantity,
                    vendorCode: $vendor->vendor_code,
                    unitPrice: (float)$vendor->price
                );
            }
        }

        return CheckoutErrorCode::OUT_OF_STOCK;
    }

    private function isProductAvailableInCatalog(string $productCode): bool
    {
        return DB::table('vendor_products')
            ->where('product_code', $productCode)
            ->exists();
    }

    private function getVendorsOrderedByPrice(string $productCode): Collection
    {
        return DB::table('vendor_products')
            ->where('product_code', $productCode)
            ->orderBy('price', 'asc')
            ->get();
    }

    private function createOrder(array $allocations): Order
    {
        $subtotal = collect($allocations)->sum(fn(ProductAllocationDTO $a) => $a->quantity * $a->unitPrice);

        $discountInput = new DiscountInputDTO(collect($allocations));
        $discount = $this->discountEngine->calculate($discountInput);
        $total = max(0, $subtotal - $discount);

        return Order::create([
            'subtotal_price' => $subtotal,
            'discount' => $discount,
            'total_price' => $total,
        ]);
    }

    private function createSubOrder(Order $order, string $vendorCode, array $allocations): SubOrder
    {
        $itemsSnapshot = array_map(fn(ProductAllocationDTO $a) => $a->toArray(), $allocations);

        return SubOrder::create([
            'order_id' => $order->id,
            'vendor_code' => $vendorCode,
            'status' => OrderStatus::CREATED,
            'items_snapshot' => $itemsSnapshot,
        ]);
    }
}
