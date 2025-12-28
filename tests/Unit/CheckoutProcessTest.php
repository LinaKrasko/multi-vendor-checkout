<?php

namespace Tests\Unit;

use App\DTOs\CartItemDTO;
use App\Enums\OrderStatus;
use App\Jobs\NotifyVendorJob;
use App\Models\CategoryDiscountRule;
use App\Models\QuantityDiscountRule;
use App\Models\VendorProduct;
use App\Services\OrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CheckoutProcessTest extends TestCase
{
    use RefreshDatabase;

    private OrderCheckoutService $checkoutService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutService = $this->app->make(OrderCheckoutService::class);
    }

    public function test_proper_vendor_grouping(): void
    {
        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 100,
            'stock' => 10,
            'category' => 'CAT1'
        ]);

        VendorProduct::create([
            'vendor_code' => 'VEND-B',
            'product_code' => 'PROD-2',
            'price' => 200,
            'stock' => 10,
            'category' => 'CAT2'
        ]);

        $payload = [
            'items' => [
                ['product_code' => 'PROD-1', 'quantity' => 1],
                ['product_code' => 'PROD-2', 'quantity' => 1],
            ]
        ];

        $result = $this->checkoutService->checkout($payload);

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->order->subOrders);

        $vendorCodes = $result->order->subOrders->pluck('vendor_code')->toArray();
        $this->assertContains('VEND-A', $vendorCodes);
        $this->assertContains('VEND-B', $vendorCodes);
    }

    public function test_discount_application(): void
    {
        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 100,
            'stock' => 20,
            'category' => 'CAT1'
        ]);

        QuantityDiscountRule::create([
            'min_qty' => 10,
            'percent' => 10,
            'is_enabled' => true
        ]);

        CategoryDiscountRule::create([
            'category' => 'CAT1',
            'percent' => 20,
            'is_enabled' => true
        ]);

        $payload = [
            'items' => [
                ['product_code' => 'PROD-1', 'quantity' => 10],
            ]
        ];

        $result = $this->checkoutService->checkout($payload);

        $this->assertTrue($result->success);
        $this->assertEquals(1000, $result->order->subtotal_price);
        $this->assertEquals(300, $result->order->discount);
        $this->assertEquals(700, $result->order->total_price);
    }

    public function test_job_dispatching(): void
    {
        Bus::fake();

        VendorProduct::create([
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 100,
            'stock' => 10,
        ]);

        VendorProduct::create([
            'vendor_code' => 'VEND-B',
            'product_code' => 'PROD-2',
            'price' => 200,
            'stock' => 10,
        ]);

        $payload = [
            'items' => [
                ['product_code' => 'PROD-1', 'quantity' => 1],
                ['product_code' => 'PROD-2', 'quantity' => 1],
            ]
        ];

        $result = $this->checkoutService->checkout($payload);

        $this->assertTrue($result->success);

        Bus::assertDispatched(NotifyVendorJob::class, 2);
    }
}
