<?php

use App\Models\PublicIdentifierMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private function tables(): array
    {
        return [
            'suppliers' => true,
            'purchase_orders' => false,
            'purchase_order_items' => false,
            'purchase_receipts' => false,
            'vendor_bills' => false,
            'vendor_bill_items' => false,
            'vendor_payments' => false,
            'vendor_payment_allocations' => false,
            'purchase_receipt_items' => false,
            'vendor_bill_receipt_matches' => false,
        ];
    }

    public function up(): void
    {
        PublicIdentifierMigration::up($this->tables());
    }

    public function down(): void
    {
        PublicIdentifierMigration::down($this->tables());
    }
};
