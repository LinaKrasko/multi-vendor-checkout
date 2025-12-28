<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quantity_discount_rules', function (Blueprint $table) {
            $table->id();
            $table->integer('min_qty');
            $table->decimal('percent', 5, 2);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('category_discount_rules', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->decimal('percent', 5, 2);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quantity_discount_rules');
        Schema::dropIfExists('category_discount_rules');
    }
};
