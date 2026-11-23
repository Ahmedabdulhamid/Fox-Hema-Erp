<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('locale', 2);
            $table->string('status')->default('pending')->index();
            $table->string('access', 64);
            $table->text('error')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
        Schema::create('product_export_products', function (Blueprint $table) {
            $table->foreignId('export_id')->constrained('product_exports')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->primary(['export_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_export_products');
        Schema::dropIfExists('product_exports');
    }
};
