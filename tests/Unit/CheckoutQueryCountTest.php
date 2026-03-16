<?php

namespace Tests\Unit;

use App\Models\VendorProduct;
use App\Services\OrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckoutQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private OrderCheckoutService $checkoutService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutService = $this->app->make(OrderCheckoutService::class);
    }

    /**
     * This test will FAIL before the refactor (fires 2 SELECT queries per item)
     * and PASS after (1 total: a single whereIn for both existence check and vendor offers).
     */
    public function test_allocation_uses_two_select_queries_on_vendor_products_regardless_of_cart_size(): void
    {
        foreach (range(1, 4) as $i) {
            VendorProduct::create([
                'vendor_code' => "VEND-{$i}",
                'product_code' => "PROD-{$i}",
                'price' => 10 * $i,
                'stock' => 10,
            ]);
        }

        $payload = [
            'items' => array_map(
                fn($i) => ['product_code' => "PROD-{$i}", 'quantity' => 1],
                range(1, 4)
            ),
        ];

        DB::enableQueryLog();
        $this->checkoutService->checkout($payload);
        $selectQueries = collect(DB::getQueryLog())
            ->filter(fn($q) => stripos($q['query'], 'vendor_products') !== false
                             && stripos($q['query'], 'select') !== false);
        DB::disableQueryLog();

        $this->assertCount(
            1,
            $selectQueries,
            'Expected exactly 1 SELECT query on vendor_products: a single whereIn covering all product codes'
        );
    }

    /**
     * Verify the optimized query count holds for a larger cart, not just 4 items.
     */
    public function test_select_query_count_does_not_grow_with_cart_size(): void
    {
        foreach (range(1, 8) as $i) {
            VendorProduct::create([
                'vendor_code' => "VEND-{$i}",
                'product_code' => "PROD-{$i}",
                'price' => 10 * $i,
                'stock' => 10,
            ]);
        }

        $payload = [
            'items' => array_map(
                fn($i) => ['product_code' => "PROD-{$i}", 'quantity' => 1],
                range(1, 8)
            ),
        ];

        DB::enableQueryLog();
        $this->checkoutService->checkout($payload);
        $selectQueries = collect(DB::getQueryLog())
            ->filter(fn($q) => stripos($q['query'], 'vendor_products') !== false
                             && stripos($q['query'], 'select') !== false);
        DB::disableQueryLog();

        $this->assertCount(1, $selectQueries);
    }
}
