<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Models\SubOrder;
use App\Enums\OrderStatus;
use App\Enums\CheckoutErrorCode;
use App\Exceptions\CheckoutFailedException;
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
        try {
            return DB::transaction(function () use ($items) {
                $allocations = $this->allocateItemsToVendors($items);

                $order = $this->createOrder($allocations);

                $this->createSubOrders($order, $allocations);

                return CheckoutResultDTO::success($order);
            });
        } catch (CheckoutFailedException $e) {
            return CheckoutResultDTO::failure($e->errorCode, $e->productCode);
        }
    }

    private function allocateItemsToVendors(array $items): array
    {
        $productCodes = array_values(array_unique(
            array_map(fn($item) => $item->productCode, $items)
        ));

        $vendorsByProduct = DB::table('vendor_products')
            ->whereIn('product_code', $productCodes)
            ->orderBy('price', 'asc')
            ->get()
            ->groupBy('product_code');

        $allocations = [];

        foreach ($items as $item) {
            $vendors = $vendorsByProduct->get($item->productCode);

            if ($vendors === null) {
                throw new CheckoutFailedException(CheckoutErrorCode::UNKNOWN_PRODUCT, $item->productCode);
            }

            if ($vendors->isEmpty()) {
                throw new CheckoutFailedException(CheckoutErrorCode::NO_OFFERS, $item->productCode);
            }

            $allocated = false;
            foreach ($vendors as $vendor) {
                if ($this->stockManager->decrementStock($vendor->vendor_code, $item->productCode, $item->quantity)) {
                    $allocations[] = new ProductAllocationDTO(
                        productCode: $item->productCode,
                        quantity: $item->quantity,
                        vendorCode: $vendor->vendor_code,
                        unitPrice: (float) $vendor->price,
                    );
                    $allocated = true;
                    break;
                }
            }

            if (!$allocated) {
                throw new CheckoutFailedException(CheckoutErrorCode::OUT_OF_STOCK, $item->productCode);
            }
        }

        return $allocations;
    }

    private function createSubOrders(Order $order, array $allocations): void
    {
        $groupedByVendor = collect($allocations)->groupBy('vendorCode');

        foreach ($groupedByVendor as $vendorCode => $vendorAllocations) {
            $this->createSubOrder($order, (string) $vendorCode, $vendorAllocations->all());
        }
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
