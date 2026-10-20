<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_number', 60)->unique();
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->enum('status', ['draft', 'approved', 'in_transit', 'partially_received', 'received', 'closed', 'cancelled'])->default('draft');
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['from_location_id', 'status']);
            $table->index(['to_location_id', 'status']);
            $table->unique(['id', 'from_location_id'], 'uq_stock_transfer_source');
            $table->unique(['id', 'to_location_id'], 'uq_stock_transfer_destination');
        });

        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT chk_stock_transfer_distinct_locations CHECK (from_location_id <> to_location_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
