<?php

use App\Models\PublicIdentifierMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private function tables(): array
    {
        return [
            'discounts' => true,
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
