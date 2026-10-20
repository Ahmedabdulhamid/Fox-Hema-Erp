<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        'vendor_bills' => 'uq_vendor_bills_id_supplier',
        'vendor_payments' => 'uq_vendor_payments_id_supplier',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::KEYS as $tableName => $indexName) {
            $exists = collect(Schema::getIndexes($tableName))->contains(
                fn (array $index): bool => $index['unique'] && $index['columns'] === ['id', 'supplier_id'],
            );
            if (! $exists) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->unique(['id', 'supplier_id'], $indexName);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse(self::KEYS) as $tableName => $indexName) {
            if (Schema::hasIndex($tableName, $indexName)) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->dropUnique($indexName);
                });
            }
        }
    }
};
