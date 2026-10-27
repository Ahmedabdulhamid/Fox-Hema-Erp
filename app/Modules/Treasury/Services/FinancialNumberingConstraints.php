<?php

namespace App\Modules\Treasury\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class FinancialNumberingConstraints
{
    private const BASE_TYPES = ['TA', 'TCR', 'TCP', 'TTRF', 'TSP', 'TOB', 'TREV'];

    private const TYPES = [...self::BASE_TYPES, 'TCU', 'TRCPT', 'TALLOC', 'TRFND', 'TPM'];

    private const CONSTRAINTS = ['document_number_sequences' => 'chk_financial_sequence_scope', 'document_numbers' => 'chk_financial_number_scope'];

    /** @param list<string> $additionalTypes */
    public function validate(array $additionalTypes): void
    {
        $this->definitions($additionalTypes);
    }

    /**
     * Replace each check in one ALTER, retaining protection if replacement fails.
     *
     * @param  list<string>  $additionalTypes
     */
    public function ensure(array $additionalTypes): void
    {
        $definitions = $this->definitions($additionalTypes);
        $drop = str_contains(strtolower(DB::selectOne('SELECT VERSION() AS version')->version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        foreach ($definitions as $table => $definition) {
            $name = self::CONSTRAINTS[$table];
            $check = $this->check($table, $name);
            if ($check !== null && $this->existingTypes($check->CHECK_CLAUSE, $table) === $definition['types']) {
                continue;
            }
            $replace = $check === null ? '' : "{$drop} {$name}, ";
            DB::statement("ALTER TABLE {$table} {$replace}ADD CONSTRAINT {$name} CHECK ({$definition['predicate']})");
        }
    }

    /**
     * Validate both tables before DDL; recover missing checks from interrupted older migrations.
     *
     * @param  list<string>  $additionalTypes
     * @return array<string, array{predicate: string, types: list<string>}>
     */
    private function definitions(array $additionalTypes): array
    {
        $types = [...self::BASE_TYPES, ...$additionalTypes];
        foreach (self::CONSTRAINTS as $table => $name) {
            $columns = $table === 'document_number_sequences' ? ['location_id', 'document_type', 'global_scope'] : ['location_id', 'document_type'];
            if (! Schema::hasColumns($table, $columns)) {
                throw new LogicException('Install financial numbering before widening its ownership constraints.');
            }
            $check = $this->check($table, $name);
            if ($check !== null) {
                $types = [...$types, ...$this->existingTypes($check->CHECK_CLAUSE, $table)];
            }
            $types = [...$types, ...DB::table($table)->whereNull('location_id')->distinct()->pluck('document_type')->all()];
        }
        $types = array_values(array_unique($types));
        if (array_diff($types, self::TYPES) !== []) {
            throw new LogicException('Unknown financial numbering types require explicit review.');
        }
        sort($types);
        $quotedTypes = "'".implode("','", $types)."'";
        $definitions = [];
        foreach (self::CONSTRAINTS as $table => $name) {
            $predicate = $table === 'document_number_sequences'
                ? "(location_id IS NOT NULL AND global_scope IS NULL) OR (location_id IS NULL AND global_scope IS NOT NULL AND global_scope = document_type AND document_type IN ({$quotedTypes}))"
                : "location_id IS NOT NULL OR document_type IN ({$quotedTypes})";
            if (DB::table($table)->whereRaw("NOT ({$predicate})")->exists()) {
                throw new LogicException("Existing {$table} rows violate financial numbering ownership; no records will be rewritten.");
            }
            $definitions[$table] = ['predicate' => $predicate, 'types' => $types];
        }

        return $definitions;
    }

    private function check(string $table, string $name): ?object
    {
        return DB::selectOne('SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c
            JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME
            WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND t.TABLE_NAME = ? AND c.CONSTRAINT_NAME = ? AND t.CONSTRAINT_TYPE = ?', [$table, $name, 'CHECK']);
    }

    /**
     * Parse only known ownership predicates; engine-rendered metadata never becomes SQL.
     *
     * @return list<string>
     */
    private function existingTypes(string $clause, string $table): array
    {
        $clause = strtoupper(str_replace(['`', "\\'"], ['', "'"], $clause));
        $clause = preg_replace("/_[A-Z0-9]+(?=')/", '', $clause);
        $typeAtoms = [];
        $clause = preg_replace_callback("/DOCUMENT_TYPE\\s*(?:IN\\s*\\(\\s*('[A-Z]+'(?:\\s*,\\s*'[A-Z]+')*)\\s*\\)|=\\s*('[A-Z]+'))/", function (array $match) use (&$typeAtoms): string {
            preg_match_all("/'([A-Z]+)'/", $match[0], $types);
            if (array_diff($types[1], self::TYPES) !== []) {
                throw new LogicException('Unknown financial numbering constraint type.');
            }
            $token = 'T'.count($typeAtoms);
            $typeAtoms[$token] = array_map(fn (string $type): array => ['type:'.$type], $types[1]);

            return $token;
        }, $clause);
        $atoms = [
            '/LOCATION_ID\\s+IS\\s+NOT\\s+NULL/' => 'A', '/GLOBAL_SCOPE\\s+IS\\s+NULL/' => 'B',
            '/LOCATION_ID\\s+IS\\s+NULL/' => 'C', '/GLOBAL_SCOPE\\s+IS\\s+NOT\\s+NULL/' => 'D',
            '/GLOBAL_SCOPE\\s*=\\s*DOCUMENT_TYPE/' => 'E',
        ];
        $clause = preg_replace(array_keys($atoms), array_values($atoms), $clause);
        if (trim(preg_replace('/AND|OR|T\\d+|[A-E()]|\\s+/', '', $clause)) !== '') {
            throw new LogicException('Unrecognized financial numbering ownership constraint.');
        }
        preg_match_all('/AND|OR|T\\d+|[A-E()]/', $clause, $matches);
        if (count($matches[0]) > 512) {
            throw new LogicException('Unexpected financial numbering constraint complexity.');
        }
        $position = 0;
        $terms = $this->terms($matches[0], $position, $typeAtoms);
        if ($position !== count($matches[0])) {
            throw new LogicException('Unbalanced financial numbering constraint.');
        }
        $hasLocal = false;
        $types = [];
        foreach ($terms as $term) {
            $term = array_values(array_unique($term));
            sort($term);
            if ($term === ($table === 'document_number_sequences' ? ['A', 'B'] : ['A'])) {
                $hasLocal = true;

                continue;
            }
            $type = array_pop($term);
            $valid = $table === 'document_number_sequences' ? $term === ['C', 'D', 'E'] : in_array($term, [[], ['C']], true);
            if (! $valid || ! str_starts_with($type ?? '', 'type:')) {
                throw new LogicException('Conflicting financial numbering ownership constraint.');
            }
            $types[] = substr($type, 5);
        }
        if (! $hasLocal || array_diff(self::BASE_TYPES, $types) !== []) {
            throw new LogicException('Existing financial numbering types or local ownership are missing.');
        }
        $types = array_values(array_unique($types));
        sort($types);

        return $types;
    }

    /**
     * Expand the restricted AND/OR grammar into ownership conjunctions, honoring parentheses.
     *
     * @param  list<string>  $tokens
     * @param  array<string, list<list<string>>>  $typeAtoms
     * @return list<list<string>>
     */
    private function terms(array $tokens, int &$position, array $typeAtoms, int $minimumPrecedence = 1): array
    {
        $token = $tokens[$position++] ?? null;
        if ($token === '(') {
            $terms = $this->terms($tokens, $position, $typeAtoms);
            if (($tokens[$position++] ?? null) !== ')') {
                throw new LogicException('Unbalanced financial numbering constraint.');
            }
        } elseif (isset($typeAtoms[$token ?? ''])) {
            $terms = $typeAtoms[$token];
        } elseif (in_array($token, ['A', 'B', 'C', 'D', 'E'], true)) {
            $terms = [[$token]];
        } else {
            throw new LogicException('Invalid financial numbering constraint.');
        }
        while (($precedence = match ($tokens[$position] ?? null) {
            'OR' => 1, 'AND' => 2, default => 0
        }) >= $minimumPrecedence) {
            $position++;
            $right = $this->terms($tokens, $position, $typeAtoms, $precedence + 1);
            $combined = [];
            if ($precedence === 1) {
                $combined = [...$terms, ...$right];
            } else {
                foreach ($terms as $left) {
                    foreach ($right as $term) {
                        $combined[] = [...$left, ...$term];
                    }
                }
            }
            if (count($combined) > 256) {
                throw new LogicException('Unexpected financial numbering constraint complexity.');
            }
            $terms = $combined;
        }

        return $terms;
    }
}
