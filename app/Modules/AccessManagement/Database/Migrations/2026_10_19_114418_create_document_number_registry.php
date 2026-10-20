<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('document_type', 8);
            $table->string('location_code', 40);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->unique(['location_id', 'document_type'], 'uq_document_sequence_scope');
        });
        Schema::create('document_numbers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('document_type', 8);
            $table->uuid('document_uuid');
            $table->string('number', 60)->unique();
            $table->unsignedBigInteger('sequence_value');
            $table->timestamp('created_at');
            $table->unique(['document_type', 'document_uuid'], 'uq_document_number_identity');
            $table->unique(['location_id', 'document_type', 'sequence_value'], 'uq_document_number_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('document_numbers') && DB::table('document_numbers')->exists()) {
            throw new LogicException('Allocated document numbers require a reviewed, data-preserving rollback.');
        }
        Schema::dropIfExists('document_numbers');
        Schema::dropIfExists('document_number_sequences');
    }
};
