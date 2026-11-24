<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> */
    private const CHECKS = [
        'chk_stock_balances_on_hand_nonnegative' => 'quantity_on_hand >= 0',
        'chk_stock_balances_reserved_nonnegative' => 'quantity_reserved >= 0',
        'chk_stock_balances_reserved_within_on_hand' => 'quantity_reserved <= quantity_on_hand',
        'chk_stock_balances_available_matches' => 'quantity_available = quantity_on_hand - quantity_reserved',
    ];

    public function up(): void
    {
        $maria = $this->validateDatabase();
        $existing = $this->existingChecks($maria);
        $invalid = DB::table('stock_balances')->where(function ($query): void {
            $query->whereNull('quantity_on_hand')->orWhereNull('quantity_reserved')->orWhereNull('quantity_available')
                ->orWhere('quantity_on_hand', '<', 0)->orWhere('quantity_reserved', '<', 0)
                ->orWhereColumn('quantity_reserved', '>', 'quantity_on_hand')
                ->orWhereRaw('quantity_available <> quantity_on_hand - quantity_reserved');
        });
        $count = (clone $invalid)->count();
        if ($count > 0) {
            $ids = (clone $invalid)->orderBy('id')->limit(20)->pluck('id')->all();
            throw new RuntimeException('Stock balance constraints blocked: '.$count.' invalid historical rows; sample IDs: '.implode(', ', $ids).'. Reconcile separately; no rows were changed.');
        }
        $add = [];
        foreach (self::CHECKS as $name => $predicate) {
            if (! in_array($name, $existing, true)) {
                $add[] = "ADD CONSTRAINT {$name} CHECK ({$predicate})";
            }
        }
        if ($add !== []) {
            DB::statement('ALTER TABLE stock_balances '.implode(', ', $add));
        }
        if (count($this->existingChecks($maria)) !== count(self::CHECKS)) {
            throw new RuntimeException('Stock balance constraints require schema review after DDL; do not assume transactional rollback.');
        }
    }

    public function down(): void
    {
        $maria = $this->validateDatabase();
        $existing = $this->existingChecks($maria);
        if ($existing !== []) {
            $drop = $maria ? 'DROP CONSTRAINT ' : 'DROP CHECK ';
            DB::statement('ALTER TABLE stock_balances '.implode(', ', array_map(fn (string $name): string => $drop.$name, $existing)));
        }
        if ($this->existingChecks($maria) !== []) {
            throw new RuntimeException('Stock balance constraint rollback requires schema review.');
        }
    }

    private function validateDatabase(): bool
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Stock balance CHECK enforcement requires MySQL 8.0.16+ or MariaDB 10.3.10+.');
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException('Stock balance DDL must run outside an application transaction; drain writers before migrating.');
        }
        if (DB::connection()->getTablePrefix() !== '') {
            throw new RuntimeException('Stock balance constraints require the unprefixed project schema; review custom table prefixes separately.');
        }
        $server = DB::selectOne('SELECT VERSION() AS version');
        $maria = $this->validateServerVersion((string) $server->version);
        if ($maria) {
            $settings = DB::selectOne('SELECT @@SESSION.check_constraint_checks AS session_checks, @@GLOBAL.check_constraint_checks AS global_checks');
            if ((int) $settings->session_checks !== 1 || (int) $settings->global_checks !== 1) {
                throw new RuntimeException('MariaDB CHECK enforcement must already be enabled globally and for this session.');
            }
        }
        $table = DB::selectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_balances'");
        if ($table === null || strtolower((string) $table->ENGINE) !== 'innodb') {
            throw new RuntimeException('Stock balance constraints require the existing InnoDB stock_balances table.');
        }
        $columns = DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_balances' AND COLUMN_NAME IN ('quantity_on_hand', 'quantity_reserved', 'quantity_available')");
        if (count($columns) !== 3) {
            throw new RuntimeException('Stock balance quantity columns require schema review.');
        }
        foreach ($columns as $column) {
            if (strtolower($column->COLUMN_TYPE) !== 'decimal(18,4)' || $column->IS_NULLABLE !== 'NO'
                || ! preg_match("/^'?0(?:\\.0{1,4})?'?$/D", (string) $column->COLUMN_DEFAULT) || $column->EXTRA !== '') {
                throw new RuntimeException('Unexpected stock balance column definition: '.$column->COLUMN_NAME.'. Expected signed DECIMAL(18,4) NOT NULL DEFAULT 0 without generated expressions.');
            }
        }

        return $maria;
    }

    private function validateServerVersion(string $version): bool
    {
        $maria = str_contains(strtolower($version), 'mariadb');
        $pattern = $maria ? '/^(?:5\.5\.5-)?(\d+\.\d+\.\d+)-MariaDB/i' : '/^(\d+\.\d+\.\d+)(?:[-.].*)?$/D';
        if (! preg_match($pattern, $version, $match)
            || version_compare($match[1], $maria ? '10.3.10' : '8.0.16', '<')) {
            throw new RuntimeException('Unsupported stock balance CHECK server: '.$version.'. Requires MySQL 8.0.16+ or MariaDB 10.3.10+ with inspectable enforced checks.');
        }

        return $maria;
    }

    /** @return list<string> */
    private function existingChecks(bool $maria): array
    {
        $enforcement = $maria ? "'YES'" : 'tc.ENFORCED';
        $tableJoin = $maria ? ' AND cc.TABLE_NAME = tc.TABLE_NAME' : '';
        $names = array_keys(self::CHECKS);
        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $records = DB::select("SELECT tc.CONSTRAINT_NAME, tc.TABLE_NAME, tc.CONSTRAINT_TYPE, cc.CHECK_CLAUSE, {$enforcement} AS enforced FROM information_schema.TABLE_CONSTRAINTS tc LEFT JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME{$tableJoin} WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.CONSTRAINT_NAME IN ({$placeholders})", $names);
        $existing = [];
        foreach ($records as $record) {
            $name = $record->CONSTRAINT_NAME;
            if (! array_key_exists($name, self::CHECKS)) {
                throw new RuntimeException('Conflicting stock balance constraint name requires manual review: '.$name);
            }
            $expected = str_replace(' ', '', self::CHECKS[$name]);
            $allowed = [$expected];
            if ($name === 'chk_stock_balances_available_matches') {
                $allowed[] = 'quantity_available=(quantity_on_hand-quantity_reserved)';
            }
            if ($record->TABLE_NAME !== 'stock_balances' || $record->CONSTRAINT_TYPE !== 'CHECK'
                || $record->enforced !== 'YES' || in_array($name, $existing, true)
                || ! in_array($this->normalizePredicate($record->CHECK_CLAUSE ?? ''), $allowed, true)) {
                throw new RuntimeException('Conflicting or unenforced stock balance constraint requires manual review: '.$name.'. No constraints were replaced.');
            }
            $existing[] = $name;
        }

        return $existing;
    }

    private function normalizePredicate(string $predicate): string
    {
        $predicate = strtolower(preg_replace('/[\s`]+/', '', $predicate));
        while (str_starts_with($predicate, '(') && str_ends_with($predicate, ')')) {
            $depth = 0;
            $last = strlen($predicate) - 1;
            for ($index = 0; $index <= $last; $index++) {
                $depth += match ($predicate[$index]) {
                    '(' => 1, ')' => -1, default => 0
                };
                if ($depth === 0 && $index !== $last) {
                    return $predicate;
                }
            }
            $predicate = substr($predicate, 1, -1);
        }

        return $predicate;
    }
};
