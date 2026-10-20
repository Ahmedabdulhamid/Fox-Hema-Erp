<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->id();

            $table->string('session_number', 60)->unique();

            $table->foreignId('pos_device_id');

            $table->foreignId('location_id')
                ->constrained('locations')
                ->restrictOnDelete();

            $table->foreignId('opened_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('status', [
                'open',
                'closing',
                'closed',
            ])->default('open');

            $table->dateTime('opened_at');

            $table->dateTime('closing_started_at')->nullable();

            $table->dateTime('closed_at')->nullable();

            $table->decimal('opening_cash', 20, 4)->default(0);

            $table->decimal('expected_cash', 20, 4)->nullable();

            $table->decimal('counted_cash', 20, 4)->nullable();

            $table->decimal('cash_difference', 20, 4)->nullable();

            $table->text('cash_difference_reason')->nullable();

            $table->foreignId('closing_approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('closing_approved_at')->nullable();

            $table->text('notes')->nullable();

            $table->index(['pos_device_id', 'status']);

            $table->index(['location_id', 'status']);

            $table->unique(
                ['id', 'pos_device_id', 'location_id'],
                'uq_session_device_location'
            );

            $table->foreign(
                ['pos_device_id', 'location_id'],
                'fk_session_device_location'
            )
                ->references(['id', 'location_id'])
                ->on('pos_devices')
                ->restrictOnDelete();

            $table->timestamps();
        });

        DB::statement('
    ALTER TABLE pos_sessions
    ADD CONSTRAINT chk_pos_sessions_cash
    CHECK (
        opening_cash >= 0
        AND (expected_cash IS NULL OR expected_cash >= 0)
        AND (counted_cash IS NULL OR counted_cash >= 0)
    )
');
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
