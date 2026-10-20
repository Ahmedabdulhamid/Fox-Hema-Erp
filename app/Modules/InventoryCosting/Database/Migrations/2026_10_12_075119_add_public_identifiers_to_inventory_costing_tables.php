<?php

use App\Models\PublicIdentifierMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private function tables(): array
    {
        return [
            'inventory_valuation_settings' => false,
            'inventory_cost_states' => false,
            'fifo_layers' => false,
            'inventory_cost_entries' => false,
            'fifo_layer_consumptions' => false,
            'inventory_standard_costs' => false,
            'inventory_standard_cost_revisions' => false,
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
