<?php

use App\Models\PublicIdentifierMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private function tables(): array
    {
        return [
            'brands' => true,
            'categories' => true,
            'sizes' => true,
            'colors' => true,
            'products' => true,
            'product_variants' => false,
            'product_images' => false,
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
