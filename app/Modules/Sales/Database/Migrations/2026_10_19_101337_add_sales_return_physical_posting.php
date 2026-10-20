<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->char('posting_payload_hash', 64)->nullable();
            $table->char('posting_evidence_hash', 64)->nullable();
            $table->char('posting_revision', 64)->nullable();
        });
        Schema::create('sales_return_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slice_key', 80);
            $table->char('operation_identity', 64)->unique();
            $table->unsignedBigInteger('sales_return_id');
            $table->unsignedBigInteger('sales_return_item_id');
            $table->unsignedBigInteger('sales_delivery_item_id');
            $table->foreignId('original_cost_entry_id')->constrained('inventory_cost_entries')->restrictOnDelete();
            $table->foreignId('fifo_consumption_id')->nullable()->constrained('fifo_layer_consumptions')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->enum('method', ['fifo', 'avco', 'standard']);
            $table->enum('disposition', ['restock', 'damaged', 'scrap']);
            $table->decimal('quantity', 18, 4);
            $table->decimal('original_unit_cost', 18, 6);
            $table->decimal('original_cost', 20, 4);
            $table->decimal('carrying_value', 20, 4);
            $table->decimal('return_variance', 20, 4)->default(0);
            $table->decimal('loss_value', 20, 4)->default(0);
            $table->unsignedBigInteger('original_standard_revision_id')->nullable();
            $table->unsignedBigInteger('current_standard_revision_id')->nullable();
            $table->foreign('original_standard_revision_id', 'fk_return_original_standard')->references('id')->on('inventory_standard_cost_revisions')->restrictOnDelete();
            $table->foreign('current_standard_revision_id', 'fk_return_current_standard')->references('id')->on('inventory_standard_cost_revisions')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at');
            $table->unique(['sales_return_item_id', 'slice_key'], 'uq_return_cost_slice');
            $table->unique(['id', 'location_id', 'variant_id'], 'uq_return_cost_custody_owner');
            $table->foreign(['sales_return_item_id', 'sales_return_id', 'sales_delivery_item_id'], 'fk_return_cost_source')
                ->references(['id', 'sales_return_id', 'sales_delivery_item_id'])->on('sales_return_items')->restrictOnDelete();
            $table->index(['original_cost_entry_id', 'id'], 'idx_return_cost_budget');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE sales_return_cost_allocations ADD CONSTRAINT chk_return_cost_values CHECK (quantity > 0 AND original_unit_cost >= 0 AND original_cost >= 0 AND carrying_value >= 0 AND loss_value >= 0 AND ((method = 'fifo' AND fifo_consumption_id IS NOT NULL) OR (method <> 'fifo' AND fifo_consumption_id IS NULL)) AND original_cost = carrying_value + loss_value + return_variance)");
        Schema::table('fifo_layers', function (Blueprint $table): void {
            // NULL keeps existing layers and their signed historical provenance unchanged.
            $table->decimal('initial_value', 20, 4)->nullable();
            $table->decimal('remaining_value', 20, 4)->nullable();
            $table->dateTime('available_at')->nullable();
            $table->foreignId('return_cost_allocation_id')->nullable()->unique()->constrained('sales_return_cost_allocations')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE fifo_layers ADD CONSTRAINT chk_fifo_exact_value CHECK ((initial_value IS NULL AND remaining_value IS NULL AND available_at IS NULL AND return_cost_allocation_id IS NULL) OR (initial_value IS NOT NULL AND remaining_value IS NOT NULL AND available_at IS NOT NULL AND initial_value >= 0 AND remaining_value >= 0 AND remaining_value <= initial_value AND (remaining_qty <> 0 OR remaining_value = 0)))');
        Schema::create('sales_return_posted_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sales_return_id');
            $table->unsignedBigInteger('sales_return_item_id');
            $table->unsignedBigInteger('sales_delivery_item_id');
            $table->unsignedBigInteger('sales_invoice_delivery_match_id')->nullable();
            $table->unsignedBigInteger('sales_invoice_item_id')->nullable();
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->restrictOnDelete();
            $table->string('allocation_key', 80);
            $table->decimal('quantity', 18, 4);
            $table->foreign(['sales_return_item_id', 'sales_return_id', 'sales_delivery_item_id'], 'fk_return_posted_allocation_source')
                ->references(['id', 'sales_return_id', 'sales_delivery_item_id'])->on('sales_return_items')->restrictOnDelete();
            $table->foreign(['sales_invoice_delivery_match_id', 'sales_invoice_item_id', 'sales_delivery_item_id'], 'fk_return_posted_allocation_match')
                ->references(['id', 'sales_invoice_item_id', 'sales_delivery_item_id'])->on('sales_invoice_delivery_matches')->restrictOnDelete();
            $table->foreign(['sales_invoice_item_id', 'sales_invoice_id'], 'fk_return_posted_allocation_invoice')
                ->references(['id', 'sales_invoice_id'])->on('sales_invoice_items')->restrictOnDelete();
            $table->unique(['sales_return_item_id', 'allocation_key'], 'uq_return_posted_allocation');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE sales_return_posted_allocations ADD CONSTRAINT chk_return_posted_allocation CHECK (quantity > 0 AND ((sales_invoice_delivery_match_id IS NULL AND sales_invoice_item_id IS NULL AND sales_invoice_id IS NULL) OR (sales_invoice_delivery_match_id IS NOT NULL AND sales_invoice_item_id IS NOT NULL AND sales_invoice_id IS NOT NULL)))');
        Schema::create('quarantine_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('return_cost_allocation_id')->unique()->constrained('sales_return_cost_allocations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('original_cost', 20, 4);
            $table->decimal('provisional_carrying_value', 20, 4);
            $table->string('valuation_policy', 80)->default('original-cost-provisional-pending-inspection');
            $table->string('inspection_status', 40)->default('pending_inspection');
            $table->text('condition_notes');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('received_at');
            $table->index(['location_id', 'variant_id'], 'idx_quarantine_custody');
            $table->foreign(['return_cost_allocation_id', 'location_id', 'variant_id'], 'fk_quarantine_custody_owner')
                ->references(['id', 'location_id', 'variant_id'])->on('sales_return_cost_allocations')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE quarantine_receipts ADD CONSTRAINT chk_quarantine_receipt CHECK (quantity > 0 AND original_cost >= 0 AND provisional_carrying_value = original_cost)');
        Schema::create('quarantine_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quarantine_receipt_id')->constrained('quarantine_receipts')->restrictOnDelete();
            $table->uuid('submission_id')->unique();
            $table->text('observations');
            $table->string('status', 40)->default('awaiting_disposition_decision');
            $table->foreignId('inspected_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('inspected_at');
            $table->char('payload_hash', 64);
            $table->timestamps();
        });
        Schema::create('sales_return_scrap_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('return_cost_allocation_id')->unique()->constrained('sales_return_cost_allocations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('original_cost', 20, 4);
            $table->decimal('operational_loss_value', 20, 4);
            $table->string('disposal_status', 40)->default('pending_disposal');
            $table->string('accounting_status', 40)->default('not_recorded');
            $table->text('reason');
            $table->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('accepted_at');
            $table->foreign(['return_cost_allocation_id', 'location_id', 'variant_id'], 'fk_scrap_custody_owner')
                ->references(['id', 'location_id', 'variant_id'])->on('sales_return_cost_allocations')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE sales_return_scrap_receipts ADD CONSTRAINT chk_return_scrap_values CHECK (quantity > 0 AND original_cost >= 0 AND operational_loss_value = original_cost)');
    }

    public function down(): void
    {
        throw new LogicException('Physical return and custody evidence require a reviewed, data-preserving rollback.');
    }
};
