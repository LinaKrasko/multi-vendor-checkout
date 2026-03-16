<?php

namespace Tests\Unit;

use App\Enums\CheckoutErrorCode;
use App\Models\Order;
use App\Models\VendorProduct;
use App\Services\OrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutErrorPathsTest extends TestCase
{
    use RefreshDatabase;

    private OrderCheckoutService $checkoutService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutService = $this->app->make(OrderCheckoutService::class);
    }

    public function test_unknown_product_returns_failure(): void
    {
        $result = $this->checkoutService->checkout([
            'items' => [['product_code' => 'NONEXISTENT', 'quantity' => 1]],
        ]);

        $this->assertFalse($result->success);
        $this->assertEquals(CheckoutErrorCode::UNKNOWN_PRODUCT, $result->errorCode);
        $this->assertEquals('NONEXISTENT', $result->productCode);
    }

    public function test_unknown_product_does_not_create_order(): void
    {
        $this->checkoutService->checkout([
            'items' => [['product_code' => 'NONEXISTENT', 'quantity' => 1]],
        ]);

        $this->assertEquals(0, Order::count());
    }

    public function test_out_of_stock_returns_failure(): void
    {
        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 10.0,
            'stock' => 0,
        ]);

        $result = $this->checkoutService->checkout([
            'items' => [['product_code' => 'PROD-1', 'quantity' => 1]],
        ]);

        $this->assertFalse($result->success);
        $this->assertEquals(CheckoutErrorCode::OUT_OF_STOCK, $result->errorCode);
        $this->assertEquals('PROD-1', $result->productCode);
    }

    public function test_out_of_stock_does_not_create_order(): void
    {
        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 10.0,
            'stock' => 0,
        ]);

        $this->checkoutService->checkout([
            'items' => [['product_code' => 'PROD-1', 'quantity' => 1]],
        ]);

        $this->assertEquals(0, Order::count());
    }

    public function test_partial_failure_rolls_back_entire_order(): void
    {
        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 10.0,
            'stock' => 5,
        ]);
        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-2',
            'price' => 20.0,
            'stock' => 0,
        ]);

        $result = $this->checkoutService->checkout([
            'items' => [
                ['product_code' => 'PROD-1', 'quantity' => 1],
                ['product_code' => 'PROD-2', 'quantity' => 1],
            ],
        ]);

        $this->assertFalse($result->success);
        $this->assertEquals(0, Order::count());
        $this->assertEquals(5, VendorProduct::where('product_code', 'PROD-1')->value('stock'));
    }

    public function test_quantity_exceeding_stock_returns_out_of_stock(): void
    {
        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 10.0,
            'stock' => 3,
        ]);

        $result = $this->checkoutService->checkout([
            'items' => [['product_code' => 'PROD-1', 'quantity' => 5]],
        ]);

        $this->assertFalse($result->success);
        $this->assertEquals(CheckoutErrorCode::OUT_OF_STOCK, $result->errorCode);
    }

    public function test_cheapest_vendor_used_when_multiple_vendors_offer_same_product(): void
    {
        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 20.0,
            'stock' => 5,
        ]);
        VendorProduct::create([
            'vendor_code' => 'VEND-B',
            'product_code' => 'PROD-1',
            'price' => 10.0,
            'stock' => 5,
        ]);

        $result = $this->checkoutService->checkout([
            'items' => [['product_code' => 'PROD-1', 'quantity' => 1]],
        ]);

        $this->assertTrue($result->success);
        $this->assertEquals('VEND-B', $result->order->subOrders->first()->vendor_code);
    }

    public function test_falls_back_to_second_vendor_when_first_is_out_of_stock(): void
    {
        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 10.0,
            'stock' => 0,
        ]);
        VendorProduct::create([
            'vendor_code' => 'VEND-B',
            'product_code' => 'PROD-1',
            'price' => 20.0,
            'stock' => 5,
        ]);

        $result = $this->checkoutService->checkout([
            'items' => [['product_code' => 'PROD-1', 'quantity' => 1]],
        ]);

        $this->assertTrue($result->success);
        $this->assertEquals('VEND-B', $result->order->subOrders->first()->vendor_code);
    }
}
