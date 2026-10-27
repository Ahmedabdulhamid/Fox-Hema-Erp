<?php

namespace App\Modules\Taxes\Services;

use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TaxReferenceChecker
{
    protected string $column = 'tax_id';

    protected string $pivot = 'product_taxes';

    private function snapshotReferences(array $snapshot, int $id): bool
    {
        if ($this->column === 'tax_id' && collect($snapshot['taxes'] ?? [])->contains(fn ($tax): bool => (int) ($tax['id'] ?? 0) === $id)) {
            return true;
        }
        if ($this->column === 'discount_id' && (int) ($snapshot['discount']['id'] ?? 0) === $id) {
            return true;
        }
        foreach ($snapshot as $value) {
            if (is_array($value) && $this->snapshotReferences($value, $id)) {
                return true;
            }
        }

        return false;
    }

    public function isReferenced(Model $definition): bool
    {
        return $definition->products()->exists() || $this->hasHistoricalReferences($definition);
    }

    public function hasHistoricalReferences(Model $definition): bool
    {
        $tables = Schema::getTables(schema: Schema::getCurrentSchemaName());
        $connection = DB::connection();
        $usesInformationSchema = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $columnsByTable = [];

        if ($usesInformationSchema) {
            $columnNames = array_unique([$this->column, 'commercial_snapshot', 'variant_id']);
            $tableNames = array_column($tables, 'name');

            foreach (DB::table('information_schema.columns')
                ->where('table_schema', Schema::getCurrentSchemaName())
                ->whereIn('table_name', $tableNames)
                ->whereIn('column_name', $columnNames)
                ->selectRaw('table_name AS table_name, column_name AS column_name')
                ->get() as $column) {
                $columnsByTable[$column->table_name][strtolower($column->column_name)] = true;
            }
        }

        $hasColumn = $usesInformationSchema
            ? static fn (string $table, string $column): bool => isset($columnsByTable[$table][strtolower($column)])
            : static fn (string $table, string $column): bool => Schema::hasColumn($table, $column);

        foreach ($tables as $table) {
            $name = $table['name'];
            if ($name !== $this->pivot && $hasColumn($name, $this->column)
                && DB::table($name)->where($this->column, $definition->getKey())->exists()) {
                return true;
            }
        }
        foreach (['sales_order_items', 'purchase_order_items', 'sales_invoice_items', 'vendor_bill_items', 'sales_credit_note_items'] as $table) {
            if (! $hasColumn($table, 'commercial_snapshot')) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull('commercial_snapshot')->select(['id', 'commercial_snapshot'])->orderBy('id')->lazyById(200) as $row) {
                if ($this->snapshotReferences(json_decode($row->commercial_snapshot, true) ?? [], (int) $definition->getKey())) {
                    return true;
                }
            }
        }
        $products = $definition->products()->select('products.id');
        $variants = ProductVariant::query()->select('id')->whereIn('product_id', $products);
        foreach (['purchase_order_items', 'purchase_receipt_items', 'vendor_bill_items', 'sales_order_items', 'sales_delivery_items', 'sales_invoice_items', 'sales_return_items', 'sales_credit_note_items'] as $table) {
            if ($hasColumn($table, 'variant_id') && DB::table($table)->whereIn('variant_id', clone $variants)->exists()) {
                return true;
            }
        }

        return false;
    }
}
