<?php

use App\Modules\Treasury\Services\FinancialNumberingConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string, array<string, array{0: string, 1?: int|string}>> */
    private function columns(): array
    {
        return [
            'payments' => [
                'customer_id' => ['foreign', 'payment_customers'], 'payment_date' => ['date'],
                'treasury_account_id' => ['foreign', 'treasury_accounts'],
            ],
            'payment_allocations' => [
                'code' => ['string', 40], 'operation_key' => ['uuid'], 'payload_fingerprint' => ['char', 64],
                'allocated_by' => ['foreign', 'users'], 'allocated_at' => ['timestamp'],
                'released_at' => ['timestamp'], 'released_by' => ['foreign', 'users'],
                'release_key' => ['uuid'], 'release_fingerprint' => ['char', 64], 'release_reason' => ['text'],
            ],
            'payment_refunds' => [
                'payload_fingerprint' => ['char', 64], 'refund_date' => ['date'], 'recovered_at' => ['timestamp'],
                'recovered_by' => ['foreign', 'users'], 'recovery_key' => ['uuid'], 'recovery_fingerprint' => ['char', 64],
                'recovery_reason' => ['text'], 'recovery_reference' => ['string', 191],
                'treasury_account_id' => ['foreign', 'treasury_accounts'],
                'treasury_operation_id' => ['foreign', 'treasury_operations'],
                'recovery_operation_id' => ['foreign', 'treasury_operations'],
            ],
        ];
    }

    /** @return array<string, list<list<string>>> */
    private function uniqueIndexes(): array
    {
        return [
            'payment_customers' => [['uuid'], ['code']],
            'customer_invoice_accounts' => [['uuid'], ['sales_invoice_id'], ['operation_key']],
            'payment_allocations' => [['code'], ['operation_key'], ['release_key']],
            'payment_refunds' => [['recovery_key'], ['treasury_operation_id'], ['recovery_operation_id']],
        ];
    }

    public function up(): void
    {
        $numbering = app(FinancialNumberingConstraints::class);
        $numbering->validate(['TCU', 'TRCPT', 'TALLOC', 'TRFND']);
        $this->preflight();
        if (! Schema::hasTable('payment_customers')) {
            Schema::create('payment_customers', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('code', 40)->unique();
                $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
                $table->string('name', 191);
                $table->string('phone', 60)->nullable();
                $table->string('email', 191)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['location_id', 'name']);
            });
        }
        if (! Schema::hasTable('customer_invoice_accounts')) {
            Schema::create('customer_invoice_accounts', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('customer_id')->constrained('payment_customers')->restrictOnDelete();
                $table->foreignId('sales_invoice_id')->unique()->constrained('sales_invoices')->restrictOnDelete();
                $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
                $table->string('settlement_terms', 12)->nullable();
                $table->decimal('planned_immediate_amount', 20, 4)->nullable();
                $table->date('due_date')->nullable();
                $table->uuid('operation_key')->unique();
                $table->char('payload_fingerprint', 64);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
        foreach ($this->columns() as $name => $columns) {
            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn($name, $column)) {
                    Schema::table($name, function (Blueprint $table) use ($column, $definition): void {
                        $type = $definition[0];
                        match ($type) {
                            'foreign' => $table->foreignId($column)->nullable(),
                            'string', 'char' => $table->{$type}($column, $definition[1])->nullable(),
                            default => $table->{$type}($column)->nullable(),
                        };
                    });
                }
                if ($definition[0] === 'foreign') {
                    $this->foreign($name, $column, $definition[1]);
                }
            }
        }
        foreach ([
            ['payment_customers', 'location_id', 'locations'], ['payment_customers', 'created_by', 'users'],
            ['customer_invoice_accounts', 'customer_id', 'payment_customers'],
            ['customer_invoice_accounts', 'sales_invoice_id', 'sales_invoices'],
            ['customer_invoice_accounts', 'location_id', 'locations'], ['customer_invoice_accounts', 'created_by', 'users'],
        ] as [$table, $column, $target]) {
            $this->foreign($table, $column, $target);
        }
        foreach ($this->uniqueIndexes() as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (! Schema::hasIndex($table, $columns, 'unique')) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns));
                }
            }
        }
        foreach ([
            ['payments', ['customer_id', 'status']],
            ['payment_customers', ['location_id', 'name']],
        ] as [$table, $columns]) {
            if (! Schema::hasIndex($table, $columns)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns));
            }
        }
        /** Keep a supporting foreign-key index before removing the obsolete pair uniqueness. */
        if (! Schema::hasIndex('payment_allocations', 'payment_allocations_payment_id_sales_invoice_id_index')) {
            Schema::table('payment_allocations', fn (Blueprint $table) => $table->index(['payment_id', 'sales_invoice_id']));
        }
        if (Schema::hasIndex('payment_allocations', 'uq_payment_invoice_allocation', 'unique')) {
            Schema::table('payment_allocations', fn (Blueprint $table) => $table->dropUnique('uq_payment_invoice_allocation'));
        }
        $numbering->ensure(['TCU', 'TRCPT', 'TALLOC', 'TRFND']);
    }

    /** Refuse ambiguous or incompatible existing schema before executing any DDL. */
    private function preflight(): void
    {
        foreach (['users', 'locations', 'sales_invoices', 'payments', 'payment_allocations', 'payment_refunds',
            'treasury_accounts', 'treasury_operations', 'document_numbers', 'document_number_sequences'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException("Missing prerequisite table: {$table}");
            }
        }
        if (! Schema::hasColumn('document_number_sequences', 'global_scope')) {
            throw new LogicException('Financial document numbering must be installed before repair.');
        }
        foreach ([
            'payment_customers' => ['id', 'uuid', 'code', 'location_id', 'name', 'phone', 'email', 'is_active', 'created_by', 'created_at', 'updated_at'],
            'customer_invoice_accounts' => ['id', 'uuid', 'customer_id', 'sales_invoice_id', 'location_id', 'settlement_terms',
                'planned_immediate_amount', 'due_date', 'operation_key', 'payload_fingerprint', 'created_by', 'created_at', 'updated_at'],
        ] as $table => $columns) {
            if (Schema::hasTable($table) && ! Schema::hasColumns($table, $columns)) {
                throw new LogicException("Partially created {$table} requires review; existing data will not be inferred or rewritten.");
            }
        }
        foreach ($this->columns() as $table => $columns) {
            $actual = collect(DB::select('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]))->keyBy('COLUMN_NAME');
            foreach ($columns as $column => $definition) {
                if (! $actual->has($column)) {
                    continue;
                }
                $stored = $actual[$column];
                $type = match ($definition[0]) {
                    'foreign' => 'bigint', 'string' => 'varchar', 'uuid' => 'char', default => $definition[0]
                };
                $length = $definition[0] === 'uuid' ? 36 : ($definition[1] ?? null);
                if ($stored->DATA_TYPE !== $type || $stored->IS_NULLABLE !== 'YES'
                    || ($definition[0] === 'foreign' && ! str_contains($stored->COLUMN_TYPE, 'unsigned'))
                    || (in_array($definition[0], ['string', 'char', 'uuid'], true) && (int) $stored->CHARACTER_MAXIMUM_LENGTH !== $length)) {
                    throw new LogicException("Incompatible existing column: {$table}.{$column}");
                }
                if ($definition[0] === 'foreign') {
                    $this->validateForeign($table, $column, $definition[1]);
                }
            }
        }
        foreach ($this->uniqueIndexes() as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $columns) {
                if (! Schema::hasColumns($table, $columns)) {
                    continue;
                }
                $query = DB::table($table)->select($columns);
                foreach ($columns as $column) {
                    $query->whereNotNull($column);
                }
                if ($query->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists()) {
                    throw new LogicException("Duplicate financial identifiers in {$table}; repair will not alter records.");
                }
            }
        }
    }

    private function validateForeign(string $table, string $column, string $target): void
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($foreign['columns'] === [$column] && ($foreign['foreign_table'] !== $target
                || $foreign['foreign_columns'] !== ['id'] || ! in_array($foreign['on_delete'], ['restrict', 'no action'], true))) {
                throw new LogicException("Conflicting foreign key on {$table}.{$column}");
            }
        }
        $query = DB::table($table)->whereNotNull($column);
        if (! Schema::hasTable($target)) {
            if ($query->exists()) {
                throw new LogicException("Unresolved references in {$table}.{$column}");
            }
        } elseif ($query->whereNotIn($column, DB::table($target)->select('id'))->exists()) {
            throw new LogicException("Orphaned references in {$table}.{$column}");
        }
    }

    private function foreign(string $table, string $column, string $target): void
    {
        if (! collect(Schema::getForeignKeys($table))->contains(fn (array $key): bool => $key['columns'] === [$column])) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on($target)->restrictOnDelete());
        }
    }

    public function down(): void
    {
        throw new LogicException('Financial schema repair is forward-only; existing evidence must be preserved.');
    }
};
