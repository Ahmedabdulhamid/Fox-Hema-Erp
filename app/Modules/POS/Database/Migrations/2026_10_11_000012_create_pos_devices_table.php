<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_devices', function (Blueprint $table): void {
            $table->id();

            $table->string('device_code', 80)->unique();

            $table->string('name');

            $table->foreignId('location_id')
                ->constrained('locations')
                ->restrictOnDelete();

            $table->boolean('is_active')->default(true);

            $table->dateTime('registered_at')->nullable();

            $table->foreignId('registered_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('last_seen_at')->nullable();

            $table->dateTime('revoked_at')->nullable();

            $table->index(['location_id', 'is_active']);

            $table->unique(
                ['id', 'location_id'],
                'uq_pos_device_location'
            );

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_devices');
    }
};
