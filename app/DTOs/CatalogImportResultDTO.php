<?php

namespace App\DTOs;

class CatalogImportResultDTO
{
    public function __construct(
        public readonly int $vendorProductsCount,
        public readonly int $quantityRulesCount,
        public readonly int $categoryRulesCount,
        public readonly array $warnings = [],
    ) {}
}
