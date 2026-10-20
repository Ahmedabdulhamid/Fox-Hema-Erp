<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 20, 4);
            $table->enum('scope', ['product', 'order'])->default('product');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_stackable')->default(false);
            $table->timestamps();
            $table->index(['scope', 'is_active', 'starts_at', 'ends_at'], 'idx_discounts_availability');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
