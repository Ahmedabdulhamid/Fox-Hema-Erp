<?php

use App\Models\PublicIdentifierMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private function tables(): array
    {
        return [
            'stock_transfers' => false,
            'stock_transfer_items' => false,
            'stock_transfer_shipments' => false,
            'stock_transfer_shipment_items' => false,
            'stock_transfer_receipts' => false,
            'stock_transfer_receipt_items' => false,
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
