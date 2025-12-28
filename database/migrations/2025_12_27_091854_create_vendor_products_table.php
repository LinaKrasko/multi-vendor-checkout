<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_products', function (Blueprint $table) {
            $table->string('vendor_code');
            $table->string('product_code');
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock')->default(0);

            $table->unique(['vendor_code', 'product_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_products');
    }
};
