<?php

use App\Models\PublicIdentifierMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private function tables(): array
    {
        return [
            'sales_orders' => false,
            'sales_order_items' => false,
            'sales_deliveries' => false,
            'sales_delivery_items' => false,
            'sales_invoices' => false,
            'sales_invoice_items' => false,
            'sales_invoice_delivery_matches' => false,
            'sales_returns' => false,
            'sales_return_items' => false,
            'sales_credit_notes' => false,
            'sales_credit_note_items' => false,
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
