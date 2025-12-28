<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\VendorProduct;
use App\Models\QuantityDiscountRule;
use App\Models\CategoryDiscountRule;
use Illuminate\Support\Facades\DB;

class ImportCatalog extends Command
{
    protected $signature = 'catalog:import {--truncate : Clear vendor_products first} {--path= : Custom path to catalog.json}';
    protected $description = 'Import offers into vendor_products from catalog.json (vendor_code + product_code + price + stock)';

    public function handle(): int
    {
        $path = $this->option('path') ?: storage_path('app/catalog.json');

        if (!File::exists($path)) {
            $this->error("catalog.json not found at: {$path}");
            return self::FAILURE;
        }

        $data = json_decode(File::get($path), true);
        if (!is_array($data)) {
            $this->error('Invalid JSON');
            return self::FAILURE;
        }

        $offers = $data['offers'] ?? null;
        if (!is_array($offers)) {
            $this->error("Expected key 'offers' with array of items");
            return self::FAILURE;
        }

        $quantityRules = $data['quantity_discount_rules'] ?? [];
        $categoryRules = $data['category_discount_rules'] ?? [];

        return DB::transaction(function () use ($offers, $quantityRules, $categoryRules) {
            if ($this->option('truncate')) {
                VendorProduct::query()->delete();
            }

            $vendorProductsCount = 0;
            foreach ($offers as $i => $o) {
                foreach (['vendor_code', 'product_code', 'price'] as $key) {
                    if (!isset($o[$key]) || $o[$key] === '') {
                        $this->error("Offer #{$i} missing required field: {$key}");
                        throw new \Exception("Missing required field: {$key}");
                    }
                }

                $vendorCode = (string)$o['vendor_code'];
                $productCode = (string)$o['product_code'];
                $price = (float)$o['price'];
                $stock = isset($o['stock']) ? (int)$o['stock'] : 0;
                $category = isset($o['category']) ? (string)$o['category'] : null;

                VendorProduct::updateOrCreate(
                    [
                        'vendor_code' => $vendorCode,
                        'product_code' => $productCode,
                    ],
                    [
                        'category' => $category,
                        'price' => $price,
                        'stock' => $stock,
                    ]
                );

                $vendorProductsCount++;
            }

            QuantityDiscountRule::truncate();
            foreach ($quantityRules as $rule) {
                QuantityDiscountRule::create([
                    'min_qty' => $rule['min_qty'],
                    'percent' => $rule['percent'],
                    'is_enabled' => $rule['is_enabled'] ?? true,
                ]);
            }

            CategoryDiscountRule::truncate();
            foreach ($categoryRules as $rule) {
                CategoryDiscountRule::create([
                    'category' => $rule['category'],
                    'percent' => $rule['percent'],
                    'is_enabled' => $rule['is_enabled'] ?? true,
                ]);
            }

            $this->info('Import complete');
            $this->line('vendor products imported: ' . $vendorProductsCount);
            $this->line('quantity rules imported: ' . count($quantityRules));
            $this->line('category rules imported: ' . count($categoryRules));

            return self::SUCCESS;
        });
    }
}
