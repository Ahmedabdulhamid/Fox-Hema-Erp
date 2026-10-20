<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        'purchase_orders' => ['supplier_id', 'uq_purchase_orders_id_supplier'],
        'purchase_order_items' => ['purchase_order_id', 'uq_purchase_order_items_id_order'],
        'purchase_receipts' => ['purchase_order_id', 'uq_purchase_receipts_id_order'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::KEYS as $tableName => [$parentColumn, $indexName]) {
            $exists = collect(Schema::getIndexes($tableName))->contains(
                fn (array $index): bool => $index['unique'] && $index['columns'] === ['id', $parentColumn],
            );
            if (! $exists) {
                Schema::table($tableName, function (Blueprint $table) use ($parentColumn, $indexName): void {
                    $table->unique(['id', $parentColumn], $indexName);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse(self::KEYS) as $tableName => [$parentColumn, $indexName]) {
            if (Schema::hasIndex($tableName, $indexName)) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->dropUnique($indexName);
                });
            }
        }
    }
};
