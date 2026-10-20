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
        Schema::create('sales_credit_notes', function (Blueprint $table): void {
            $table->id();

            $table->string('credit_note_number', 60)->unique();

            $table->foreignId('sales_invoice_id')
                ->constrained('sales_invoices')
                ->restrictOnDelete();

            $table->foreignId('sales_return_id')
                ->nullable()
                ->constrained('sales_returns')
                ->restrictOnDelete();

            $table->date('credit_date');

            $table->enum('status', [
                'draft',
                'posted',
                'cancelled',
            ])->default('draft');

            $table->decimal('subtotal', 20, 4)->default(0);

            $table->decimal('discount_amount', 20, 4)->default(0);

            $table->decimal('tax_amount', 20, 4)->default(0);

            $table->decimal('total_amount', 20, 4)->default(0);

            $table->text('reason')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('posted_at')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('cancelled_at')->nullable();

            $table->index(['sales_invoice_id', 'status']);

            $table->index(['credit_date', 'status']);

            $table->timestamps();
        });

        DB::statement('
    ALTER TABLE sales_credit_notes
    ADD CONSTRAINT chk_sales_credit_notes_amounts
    CHECK (
        subtotal >= 0
        AND discount_amount >= 0
        AND tax_amount >= 0
        AND total_amount >= 0
        AND discount_amount <= subtotal
    )
');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_notes');
    }
};
