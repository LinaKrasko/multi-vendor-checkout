<?php

namespace App\Services;

use App\DTOs\CatalogImportResultDTO;
use App\Exceptions\CatalogImportException;
use App\Models\CategoryDiscountRule;
use App\Models\QuantityDiscountRule;
use App\Models\VendorProduct;
use Illuminate\Support\Facades\DB;

class CatalogImportService
{
    /**
     * @throws CatalogImportException
     */
    public function import(array $data, bool $truncate = false): CatalogImportResultDTO
    {
        $offers = $data['offers'] ?? null;
        if (!is_array($offers)) {
            throw new CatalogImportException("Expected key 'offers' with array of items");
        }

        $quantityRules = $data['quantity_discount_rules'] ?? [];
        $categoryRules = $data['category_discount_rules'] ?? [];

        // Validate all data before touching the DB
        [$validatedOffers, $warnings] = $this->validateOffers($offers);
        $this->validateRules($quantityRules, ['min_qty', 'percent']);
        $this->validateRules($categoryRules, ['category', 'percent']);

        return DB::transaction(function () use ($validatedOffers, $quantityRules, $categoryRules, $truncate, $warnings) {
            if ($truncate) {
                VendorProduct::query()->delete();
            }

            if (!empty($validatedOffers)) {
                VendorProduct::upsert(
                    $validatedOffers,
                    ['vendor_code', 'product_code'],
                    ['price', 'stock', 'category'],
                );
            }

            $now = now();

            QuantityDiscountRule::truncate();
            if (!empty($quantityRules)) {
                QuantityDiscountRule::insert(array_map(fn($r) => [
                    'min_qty'    => $r['min_qty'],
                    'percent'    => $r['percent'],
                    'is_enabled' => $r['is_enabled'] ?? true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $quantityRules));
            }

            CategoryDiscountRule::truncate();
            if (!empty($categoryRules)) {
                CategoryDiscountRule::insert(array_map(fn($r) => [
                    'category'   => $r['category'],
                    'percent'    => $r['percent'],
                    'is_enabled' => $r['is_enabled'] ?? true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $categoryRules));
            }

            return new CatalogImportResultDTO(
                vendorProductsCount: count($validatedOffers),
                quantityRulesCount: count($quantityRules),
                categoryRulesCount: count($categoryRules),
                warnings: $warnings,
            );
        });
    }

    /**
     * @throws CatalogImportException
     */
    private function validateOffers(array $offers): array
    {
        $validated = [];
        $warnings  = [];

        foreach ($offers as $i => $offer) {
            foreach (['vendor_code', 'product_code', 'price'] as $field) {
                if (!isset($offer[$field]) || $offer[$field] === '') {
                    throw new CatalogImportException(
                        "Offer #{$i} missing required field: {$field}",
                        offerIndex: $i,
                        field: $field,
                    );
                }
            }

            if (!array_key_exists('stock', $offer)) {
                $warnings[] = "Offer #{$i} ({$offer['product_code']}): missing 'stock', defaulting to 0";
            }

            $validated[] = [
                'vendor_code'  => (string) $offer['vendor_code'],
                'product_code' => (string) $offer['product_code'],
                'price'        => (float) $offer['price'],
                'stock'        => array_key_exists('stock', $offer) ? (int) $offer['stock'] : 0,
                'category'     => isset($offer['category']) ? (string) $offer['category'] : null,
            ];
        }

        return [$validated, $warnings];
    }

    /**
     * @throws CatalogImportException
     */
    private function validateRules(array $rules, array $requiredFields): void
    {
        foreach ($rules as $i => $rule) {
            foreach ($requiredFields as $field) {
                if (!isset($rule[$field]) || $rule[$field] === '') {
                    throw new CatalogImportException(
                        "Rule #{$i} missing required field: {$field}",
                        offerIndex: $i,
                        field: $field,
                    );
                }
            }
        }
    }
}
