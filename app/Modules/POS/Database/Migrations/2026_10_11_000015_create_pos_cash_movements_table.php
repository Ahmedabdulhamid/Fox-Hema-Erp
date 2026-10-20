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
        Schema::create('pos_cash_movements', function (Blueprint $table): void {
            $table->id();

            $table->string('reference_number', 60)->unique();

            $table->foreignId('pos_session_id')
                ->constrained('pos_sessions')
                ->restrictOnDelete();

            $table->enum('movement_type', [
                'cash_in',
                'cash_out',
                'cash_drop',
                'correction',
            ]);

            $table->enum('direction', [
                'in',
                'out',
            ]);

            $table->decimal('amount', 20, 4);

            $table->string('reason');

            $table->foreignId('corrects_movement_id')
                ->nullable()
                ->constrained('pos_cash_movements')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('approved_at')->nullable();

            $table->dateTime('occurred_at');

            $table->index([
                'pos_session_id',
                'movement_type',
            ]);

            $table->index([
                'pos_session_id',
                'occurred_at',
            ]);

            $table->timestamps();
        });

        DB::statement("
    ALTER TABLE pos_cash_movements
    ADD CONSTRAINT chk_pos_cash_movement_amount
    CHECK (
        amount > 0
        AND (
            (movement_type = 'cash_in' AND direction = 'in')
            OR (movement_type IN ('cash_out', 'cash_drop') AND direction = 'out')
            OR (movement_type = 'correction')
        )
    )
");
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cash_movements');
    }
};
