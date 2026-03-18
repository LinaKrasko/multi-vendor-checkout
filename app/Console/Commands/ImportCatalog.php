<?php

namespace App\Console\Commands;

use App\Exceptions\CatalogImportException;
use App\Services\CatalogImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportCatalog extends Command
{
    protected $signature = 'catalog:import {--truncate : Clear vendor_products first} {--path= : Custom path to catalog.json}';
    protected $description = 'Import offers into vendor_products from catalog.json (vendor_code + product_code + price + stock)';

    public function handle(CatalogImportService $service): int
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

        try {
            $result = $service->import($data, truncate: (bool) $this->option('truncate'));
        } catch (CatalogImportException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        $this->info('Import complete');
        $this->line('vendor products imported: ' . $result->vendorProductsCount);
        $this->line('quantity rules imported: ' . $result->quantityRulesCount);
        $this->line('category rules imported: ' . $result->categoryRulesCount);

        return self::SUCCESS;
    }
}
