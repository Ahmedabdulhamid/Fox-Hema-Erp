<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->boolean('global_scope')->default(false);
            $table->string('fingerprint', 64)->unique();
            $table->string('source_hash', 64);
            $table->string('status', 32)->default('uploaded');
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedInteger('preparation_attempts')->default(0);
            $table->unsignedInteger('recovery_attempts')->default(0);
            $table->string('preparation_phase', 16)->default('parse');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
            $table->index(['actor_id', 'location_id', 'created_at']);
        });
        Schema::create('product_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('product_imports')->cascadeOnDelete();
            $table->string('sheet', 32);
            $table->unsignedInteger('row_number');
            $table->string('product_key')->default('');
            $table->json('data');
            $table->unique(['import_id', 'sheet', 'row_number'], 'product_import_row_source_unique');
            $table->index(['import_id', 'product_key']);
        });
        Schema::create('product_import_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('product_imports')->cascadeOnDelete();
            $table->string('product_key');
            $table->string('status', 32)->default('valid');
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->json('errors')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('source_rows')->default(0);
            $table->unsignedInteger('variant_count')->default(0);
            $table->unsignedInteger('image_count')->default(0);
            $table->boolean('retryable')->default(false);
            $table->timestamps();
            $table->unique(['import_id', 'product_key']);
            $table->index(['import_id', 'status', 'id']);
        });
        Schema::create('product_import_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('product_imports')->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->string('upload_name');
            $table->string('hash', 64);
            $table->unsignedBigInteger('bytes');
            $table->unique(['import_id', 'upload_name']);
        });
        Schema::create('product_import_slots', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('import_id')->nullable();
            $table->uuid('token')->nullable();
            $table->timestamp('lease_until')->nullable();
        });
        DB::table('product_import_slots')->insert(['id' => 1]);
    }

    public function down(): void
    {
        foreach (['product_import_slots', 'product_import_assets', 'product_import_entries', 'product_import_rows', 'product_imports'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
