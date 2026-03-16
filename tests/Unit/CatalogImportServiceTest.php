<?php

namespace Tests\Unit;

use App\DTOs\CatalogImportResultDTO;
use App\Exceptions\CatalogImportException;
use App\Models\CategoryDiscountRule;
use App\Models\QuantityDiscountRule;
use App\Models\VendorProduct;
use App\Services\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private CatalogImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CatalogImportService::class);
    }

    private function makeCatalog(array $overrides = []): array
    {
        return array_merge([
            'offers' => [
                ['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 10.0, 'stock' => 5, 'category' => 'toys'],
                ['vendor_code' => 'VEND-B', 'product_code' => 'PROD-2', 'price' => 20.0, 'stock' => 3],
            ],
            'quantity_discount_rules' => [
                ['min_qty' => 10, 'percent' => 5, 'is_enabled' => true],
            ],
            'category_discount_rules' => [
                ['category' => 'toys', 'percent' => 10, 'is_enabled' => true],
            ],
        ], $overrides);
    }

    // --- Import correctness ---

    public function test_imports_vendor_products(): void
    {
        $this->service->import($this->makeCatalog());

        $this->assertEquals(2, VendorProduct::count());
        $this->assertDatabaseHas('vendor_products', [
            'vendor_code' => 'VEND-A',
            'product_code' => 'PROD-1',
            'price' => 10.0,
            'stock' => 5,
            'category' => 'toys',
        ]);
    }

    public function test_imports_quantity_discount_rules(): void
    {
        $this->service->import($this->makeCatalog());

        $this->assertEquals(1, QuantityDiscountRule::count());
        $this->assertDatabaseHas('quantity_discount_rules', ['min_qty' => 10, 'percent' => 5, 'is_enabled' => true]);
    }

    public function test_imports_category_discount_rules(): void
    {
        $this->service->import($this->makeCatalog());

        $this->assertEquals(1, CategoryDiscountRule::count());
        $this->assertDatabaseHas('category_discount_rules', ['category' => 'toys', 'percent' => 10, 'is_enabled' => true]);
    }

    public function test_returns_correct_counts(): void
    {
        $result = $this->service->import($this->makeCatalog());

        $this->assertInstanceOf(CatalogImportResultDTO::class, $result);
        $this->assertEquals(2, $result->vendorProductsCount);
        $this->assertEquals(1, $result->quantityRulesCount);
        $this->assertEquals(1, $result->categoryRulesCount);
    }

    // --- truncate behaviour ---

    public function test_truncate_clears_vendor_products_before_import(): void
    {
        VendorProduct::create(['vendor_code' => 'OLD', 'product_code' => 'OLD-1', 'price' => 99, 'stock' => 1]);

        $this->service->import($this->makeCatalog(), truncate: true);

        $this->assertDatabaseMissing('vendor_products', ['vendor_code' => 'OLD']);
        $this->assertEquals(2, VendorProduct::count());
    }

    /**
     * This test will FAIL before the refactor because --truncate only clears vendor_products,
     * not discount rules. After the refactor it should clear all three tables.
     */
    public function test_truncate_also_clears_discount_rules(): void
    {
        QuantityDiscountRule::create(['min_qty' => 999, 'percent' => 99, 'is_enabled' => true]);
        CategoryDiscountRule::create(['category' => 'old-cat', 'percent' => 99, 'is_enabled' => true]);

        $this->service->import($this->makeCatalog(), truncate: true);

        $this->assertDatabaseMissing('quantity_discount_rules', ['min_qty' => 999]);
        $this->assertDatabaseMissing('category_discount_rules', ['category' => 'old-cat']);
    }

    public function test_without_truncate_preserves_products_not_in_catalog(): void
    {
        VendorProduct::create(['vendor_code' => 'OLD', 'product_code' => 'OLD-1', 'price' => 99, 'stock' => 1]);

        $this->service->import($this->makeCatalog(), truncate: false);

        $this->assertDatabaseHas('vendor_products', ['vendor_code' => 'OLD']);
        $this->assertEquals(3, VendorProduct::count());
    }

    public function test_reimport_updates_price_and_stock_for_existing_product(): void
    {
        VendorProduct::create(['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 5.0, 'stock' => 1]);

        $this->service->import($this->makeCatalog());

        $product = VendorProduct::where('vendor_code', 'VEND-A')->where('product_code', 'PROD-1')->first();
        $this->assertEquals(10.0, $product->price);
        $this->assertEquals(5, $product->stock);
    }

    public function test_discount_rules_are_replaced_not_accumulated_on_reimport(): void
    {
        $this->service->import($this->makeCatalog());
        $this->service->import($this->makeCatalog());

        $this->assertEquals(1, QuantityDiscountRule::count());
        $this->assertEquals(1, CategoryDiscountRule::count());
    }

    // --- Validation ---

    public function test_missing_vendor_code_throws_catalog_import_exception(): void
    {
        $catalog = $this->makeCatalog([
            'offers' => [['product_code' => 'PROD-1', 'price' => 10.0, 'stock' => 5]],
        ]);

        $this->expectException(CatalogImportException::class);
        $this->service->import($catalog);
    }

    public function test_missing_product_code_throws_catalog_import_exception(): void
    {
        $catalog = $this->makeCatalog([
            'offers' => [['vendor_code' => 'VEND-A', 'price' => 10.0, 'stock' => 5]],
        ]);

        $this->expectException(CatalogImportException::class);
        $this->service->import($catalog);
    }

    public function test_missing_price_throws_catalog_import_exception(): void
    {
        $catalog = $this->makeCatalog([
            'offers' => [['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'stock' => 5]],
        ]);

        $this->expectException(CatalogImportException::class);
        $this->service->import($catalog);
    }

    public function test_validation_failure_on_third_offer_persists_nothing(): void
    {
        $catalog = $this->makeCatalog([
            'offers' => [
                ['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 10.0],
                ['vendor_code' => 'VEND-B', 'product_code' => 'PROD-2', 'price' => 20.0],
                ['product_code' => 'PROD-3', 'price' => 30.0], // missing vendor_code
            ],
        ]);

        try {
            $this->service->import($catalog);
        } catch (CatalogImportException) {}

        $this->assertEquals(0, VendorProduct::count());
    }

    // --- Stock warnings ---

    public function test_missing_stock_defaults_to_zero_and_adds_warning(): void
    {
        $catalog = $this->makeCatalog([
            'offers' => [['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 10.0]],
        ]);

        $result = $this->service->import($catalog);

        $this->assertEquals(0, VendorProduct::where('product_code', 'PROD-1')->value('stock'));
        $this->assertNotEmpty($result->warnings);
    }

    public function test_explicit_zero_stock_does_not_produce_warning(): void
    {
        $catalog = $this->makeCatalog([
            'offers' => [['vendor_code' => 'VEND-A', 'product_code' => 'PROD-1', 'price' => 10.0, 'stock' => 0]],
        ]);

        $result = $this->service->import($catalog);

        $this->assertEmpty($result->warnings);
    }
}
