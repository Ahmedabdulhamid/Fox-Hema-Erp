<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'tax_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_taxes');
    }
};
