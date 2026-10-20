<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_cleanup_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->timestamp('ready_at')->nullable()->index();
            $table->unique(['disk', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_cleanup_tasks');
    }
};
