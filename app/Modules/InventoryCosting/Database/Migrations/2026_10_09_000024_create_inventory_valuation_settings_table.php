<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_valuation_settings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('category_id')
                ->unique()
                ->constrained('categories')
                ->restrictOnDelete();

            $table->enum('costing_method', [
                'standard',
                'avco',
                'fifo',
            ]);

            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_valuation_settings');
    }
};
