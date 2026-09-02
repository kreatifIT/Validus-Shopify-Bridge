<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Validus product (= one Shopify variant); Validus groups
        // several rows under one Shopify product by the code's grouping key.
        Schema::create('validus_shopify_product_map', function (Blueprint $table) {
            $table->id();
            $table->string('validus_id')->unique();
            $table->string('validus_code');
            $table->string('shopify_product_id')->nullable();
            $table->string('shopify_variant_id')->nullable()->unique();
            $table->timestamps();

            $table->index('validus_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validus_shopify_product_map');
    }
};
