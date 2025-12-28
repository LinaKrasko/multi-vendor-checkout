<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id');

            $table->string('vendor_code');
            $table->string('status')->default('created');
            $table->json('items_snapshot');
            $table->timestamp('vendor_notified_at')->nullable();

            $table->timestamps();

            $table->unique(['order_id', 'vendor_code']);

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_orders');
    }
};
