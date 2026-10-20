<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Detect historical conflicts before DDL, without inventing approval evidence.
        $conflict = DB::table('sales_returns as r')->join('sales_orders as o', 'o.id', '=', 'r.sales_order_id')
            ->whereColumn('r.location_id', '!=', 'o.location_id')->value('r.id');
        $lineConflict = DB::table('sales_return_items as i')->join('sales_returns as r', 'r.id', '=', 'i.sales_return_id')
            ->join('sales_delivery_items as d', 'd.id', '=', 'i.sales_delivery_item_id')
            ->join('sales_order_items as oi', 'oi.id', '=', 'd.sales_order_item_id')
            ->join('sales_deliveries as sd', 'sd.id', '=', 'd.sales_delivery_id')
            ->where(fn ($q) => $q->whereColumn('i.variant_id', '!=', 'oi.variant_id')
                ->orWhereColumn('r.location_id', '!=', 'sd.location_id')
                ->orWhere(fn ($q) => $q->whereNotNull('r.sales_order_id')->whereColumn('r.sales_order_id', '!=', 'd.sales_order_id')))->value('i.id');
        $invoiceConflict = DB::table('sales_returns as r')->join('sales_invoices as i', 'i.id', '=', 'r.sales_invoice_id')
            ->where(fn ($q) => $q->whereColumn('r.location_id', '!=', 'i.location_id')
                ->orWhere(fn ($q) => $q->whereNotNull('r.sales_order_id')->whereColumn('r.sales_order_id', '!=', 'i.sales_order_id')))->value('r.id');
        $matchConflict = DB::table('sales_return_items as ri')->join('sales_returns as r', 'r.id', '=', 'ri.sales_return_id')
            ->join('sales_invoice_items as ii', 'ii.id', '=', 'ri.sales_invoice_item_id')
            ->leftJoin('sales_invoice_delivery_matches as m', fn ($j) => $j->on('m.sales_invoice_item_id', '=', 'ii.id')->on('m.sales_delivery_item_id', '=', 'ri.sales_delivery_item_id'))
            ->where(fn ($q) => $q->whereNull('m.id')->orWhereColumn('ii.variant_id', '!=', 'ri.variant_id')
                ->orWhere(fn ($q) => $q->whereNotNull('r.sales_invoice_id')->whereColumn('r.sales_invoice_id', '!=', 'ii.sales_invoice_id')))->value('ri.id');
        if ($conflict !== null || $lineConflict !== null || $invoiceConflict !== null || $matchConflict !== null) {
            throw new LogicException('Sales return ownership conflicts require review before migration: '.json_encode(compact('conflict', 'lineConflict', 'invoiceConflict', 'matchConflict')));
        }
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->char('submission_payload_hash', 64)->nullable();
            $table->unsignedInteger('revision_number')->default(0);
            $table->char('approval_revision', 64)->nullable();
            $table->char('approval_payload_hash', 64)->nullable();
            $table->char('approval_evidence_hash', 64)->nullable();
            $table->char('cancellation_revision', 64)->nullable();
            $table->char('cancellation_payload_hash', 64)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unique(['id', 'sales_order_id', 'location_id'], 'uq_return_source_owner');
            $table->foreign(['sales_order_id', 'location_id'], 'fk_return_order_location')->references(['id', 'location_id'])->on('sales_orders')->restrictOnDelete();
        });
        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_order_id')->nullable();
            $table->unsignedBigInteger('source_location_id')->nullable();
            $table->unsignedBigInteger('source_order_item_id')->nullable();
            $table->decimal('unbilled_quantity', 18, 4)->nullable();
            $table->unique(['id', 'sales_return_id', 'sales_delivery_item_id'], 'uq_return_item_source');
            $table->foreign(['sales_return_id', 'source_order_id', 'source_location_id'], 'fk_return_item_owner')->references(['id', 'sales_order_id', 'location_id'])->on('sales_returns')->restrictOnDelete();
            $table->foreign(['sales_delivery_item_id', 'source_order_item_id'], 'fk_return_item_delivery')->references(['id', 'sales_order_item_id'])->on('sales_delivery_items')->restrictOnDelete();
            $table->foreign(['source_order_item_id', 'source_order_id', 'variant_id'], 'fk_return_item_variant')->references(['id', 'sales_order_id', 'variant_id'])->on('sales_order_items')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE sales_return_items ADD CONSTRAINT chk_return_source_complete CHECK ((source_order_id IS NULL AND source_location_id IS NULL AND source_order_item_id IS NULL AND unbilled_quantity IS NULL) OR (source_order_id IS NOT NULL AND source_location_id IS NOT NULL AND source_order_item_id IS NOT NULL AND unbilled_quantity IS NOT NULL AND unbilled_quantity >= 0 AND unbilled_quantity <= quantity))');
        Schema::table('sales_invoice_delivery_matches', fn (Blueprint $table) => $table->unique(['id', 'sales_invoice_item_id', 'sales_delivery_item_id'], 'uq_invoice_match_source'));
        Schema::create('sales_return_invoice_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sales_return_id');
            $table->unsignedBigInteger('sales_return_item_id');
            $table->unsignedBigInteger('sales_delivery_item_id');
            $table->unsignedBigInteger('sales_invoice_delivery_match_id');
            $table->unsignedBigInteger('sales_invoice_item_id');
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->unique(['sales_return_item_id', 'sales_invoice_delivery_match_id'], 'uq_return_invoice_allocation');
            $table->foreign(['sales_return_item_id', 'sales_return_id', 'sales_delivery_item_id'], 'fk_return_allocation_item')->references(['id', 'sales_return_id', 'sales_delivery_item_id'])->on('sales_return_items')->restrictOnDelete();
            $table->foreign(['sales_invoice_delivery_match_id', 'sales_invoice_item_id', 'sales_delivery_item_id'], 'fk_return_allocation_match')->references(['id', 'sales_invoice_item_id', 'sales_delivery_item_id'])->on('sales_invoice_delivery_matches')->restrictOnDelete();
            $table->foreign(['sales_invoice_item_id', 'sales_invoice_id'], 'fk_return_allocation_invoice')->references(['id', 'sales_invoice_id'])->on('sales_invoice_items')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE sales_return_invoice_allocations ADD CONSTRAINT chk_return_allocation_positive CHECK (quantity > 0)');
        Schema::create('sales_return_quantity_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sales_return_id');
            $table->unsignedBigInteger('sales_return_item_id')->unique();
            $table->foreignId('sales_delivery_item_id')->constrained('sales_delivery_items')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->enum('status', ['active', 'released', 'consumed'])->default('active');
            $table->char('approval_identity', 64);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->foreign(['sales_return_item_id', 'sales_return_id', 'sales_delivery_item_id'], 'fk_return_claim_source')->references(['id', 'sales_return_id', 'sales_delivery_item_id'])->on('sales_return_items')->restrictOnDelete();
            $table->index(['sales_delivery_item_id', 'status'], 'idx_return_claim_capacity');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE sales_return_quantity_claims ADD CONSTRAINT chk_return_claim_state CHECK (quantity > 0 AND ((status IN ('active','consumed') AND released_at IS NULL AND released_by IS NULL AND release_reason IS NULL) OR (status = 'released' AND released_at IS NOT NULL AND released_by IS NOT NULL AND release_reason IS NOT NULL)))");
        Schema::create('sales_return_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        throw new LogicException('Return approval history requires a reviewed data-preserving rollback.');
    }
};
