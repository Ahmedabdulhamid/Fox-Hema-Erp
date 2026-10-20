<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_session_tenders', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('pos_session_id')
                ->constrained('pos_sessions')
                ->restrictOnDelete();

            $table->string('method', 50);
            // cash, card, wallet, instapay

            $table->decimal('expected_amount', 20, 4)->default(0);

            $table->decimal('verified_amount', 20, 4)->nullable();

            $table->decimal('difference_amount', 20, 4)->nullable();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('verified_at')->nullable();

            $table->text('notes')->nullable();

            $table->unique(
                ['pos_session_id', 'method'],
                'uq_session_tender_method'
            );

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_session_tenders');
    }
};
