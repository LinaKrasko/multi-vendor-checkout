<?php

namespace Tests\Unit;

use App\Discounts\CategoryDiscountRule;
use App\DTOs\DiscountInputDTO;
use App\DTOs\ProductAllocationDTO;
use App\Models\CategoryDiscountRule as CategoryDiscountRuleModel;
use App\Models\VendorProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryDiscountRuleTest extends TestCase
{
    use RefreshDatabase;

    private CategoryDiscountRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new CategoryDiscountRule();
    }

    private function makeInput(array $allocations): DiscountInputDTO
    {
        return new DiscountInputDTO(collect($allocations));
    }

    private function makeAllocation(string $productCode, int $quantity, float $unitPrice): ProductAllocationDTO
    {
        return new ProductAllocationDTO($productCode, $quantity, 'VEND-A', $unitPrice);
    }

    public function test_applies_discount_to_item_with_matching_category(): void
    {
        CategoryDiscountRuleModel::create(['category' => 'toys', 'percent' => 10, 'is_enabled' => true]);
        VendorProduct::create(['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 10, 'stock' => 10, 'category' => 'toys']);

        $input = $this->makeInput([$this->makeAllocation('PROD-1', 2, 10.0)]);

        $this->assertEquals(2.0, $this->rule->calculate($input)); // 10% of (2 * 10)
    }

    public function test_no_discount_for_item_with_non_matching_category(): void
    {
        CategoryDiscountRuleModel::create(['category' => 'toys', 'percent' => 10, 'is_enabled' => true]);
        VendorProduct::create(['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 10, 'stock' => 10, 'category' => 'electronics']);

        $input = $this->makeInput([$this->makeAllocation('PROD-1', 2, 10.0)]);

        $this->assertEquals(0.0, $this->rule->calculate($input));
    }

    public function test_only_matching_items_discounted_in_mixed_cart(): void
    {
        CategoryDiscountRuleModel::create(['category' => 'toys', 'percent' => 10, 'is_enabled' => true]);
        VendorProduct::create(['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 10, 'stock' => 10, 'category' => 'toys']);
        VendorProduct::create(['vendor_code' => 'VEND-A', 'product_code' => 'PROD-2', 'price' => 20, 'stock' => 10, 'category' => 'electronics']);

        $input = $this->makeInput([
            $this->makeAllocation('PROD-1', 1, 10.0), // toys: 10% of 10 = 1.0
            $this->makeAllocation('PROD-2', 1, 20.0), // electronics: no rule
        ]);

        $this->assertEquals(1.0, $this->rule->calculate($input));
    }

    public function test_returns_zero_when_no_rules_are_enabled(): void
    {
        CategoryDiscountRuleModel::create(['category' => 'toys', 'percent' => 10, 'is_enabled' => false]);
        VendorProduct::create(['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 10, 'stock' => 10, 'category' => 'toys']);

        $input = $this->makeInput([$this->makeAllocation('PROD-1', 1, 10.0)]);

        $this->assertEquals(0.0, $this->rule->calculate($input));
    }

    public function test_items_with_null_category_are_skipped(): void
    {
        CategoryDiscountRuleModel::create(['category' => 'toys', 'percent' => 10, 'is_enabled' => true]);
        VendorProduct::create(['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 10, 'stock' => 10, 'category' => null]);

        $input = $this->makeInput([$this->makeAllocation('PROD-1', 1, 10.0)]);

        $this->assertEquals(0.0, $this->rule->calculate($input));
    }

    public function test_returns_zero_with_empty_cart(): void
    {
        CategoryDiscountRuleModel::create(['category' => 'toys', 'percent' => 10, 'is_enabled' => true]);

        $input = $this->makeInput([]);

        $this->assertEquals(0.0, $this->rule->calculate($input));
    }

    /**
     * This test will FAIL before the refactor (fires N queries) and PASS after (1 query).
     */
    public function test_uses_single_vendor_products_query_for_multiple_items(): void
    {
        CategoryDiscountRuleModel::create(['category' => 'toys', 'percent' => 10, 'is_enabled' => true]);
        foreach (range(1, 4) as $i) {
            VendorProduct::create([
                'vendor_code' => 'VEND-A',
                'product_code' => "PROD-{$i}",
                'price' => 10 * $i,
                'stock' => 10,
                'category' => 'toys',
            ]);
        }

        $input = $this->makeInput([
            $this->makeAllocation('PROD-1', 1, 10.0),
            $this->makeAllocation('PROD-2', 1, 20.0),
            $this->makeAllocation('PROD-3', 1, 30.0),
            $this->makeAllocation('PROD-4', 1, 40.0),
        ]);

        DB::enableQueryLog();
        $this->rule->calculate($input);
        $vendorProductQueries = collect(DB::getQueryLog())
            ->filter(fn($q) => stripos($q['query'], 'vendor_products') !== false);
        DB::disableQueryLog();

        $this->assertCount(1, $vendorProductQueries, 'Expected exactly 1 vendor_products query regardless of item count');
    }
}
