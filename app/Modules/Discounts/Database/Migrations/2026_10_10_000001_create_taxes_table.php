<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('rate', 12, 4)->default(0);
            $table->enum('price_inclusion', ['excluded', 'included'])->default('excluded');
            $table->enum('usage', ['sales', 'purchases', 'both'])->default('sales');
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['usage', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
