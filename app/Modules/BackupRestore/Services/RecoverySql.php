<?php

namespace App\Modules\BackupRestore\Services;

use Closure;
use Generator;
use PDO;

/** A small dump language; comments and dump session settings are never executed. */
class RecoverySql
{
    private const ID = '[\x60][a-zA-Z_][a-zA-Z0-9_]{0,63}[\x60]';

    private const LITERAL = "'(?:[^'\\\\]|\\\\.|'')*'";

    /** @return array<string, int> Row counts derived from authenticated literal INSERTs. */
    public function prepare(string $source, string $destination, Closure $tick, ?Closure $inspectRow = null): array
    {
        if (filesize($source) > (int) config('backup-restore.restore.max_bytes')) {
            throw new BackupFailure('restore_limit_exceeded');
        }
        $tables = [];
        $references = [];
        $structures = [];
        $checks = null;
        $checkBytes = 0;
        $output = app(PrivateFiles::class)->create($destination);
        try {
            PrivateFiles::write($output, "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';\nSET NAMES utf8mb4;\nSET SESSION time_zone='+00:00';\nSET SESSION foreign_key_checks=0;\n");
            foreach ($this->statements($source, $tick) as $sql) {
                $sql = trim($sql);
                if ($sql === '') {
                    continue;
                }
                if (preg_match('~\ACREATE TABLE ('.self::ID.')\s*\((.*)\)\s*ENGINE=InnoDB(?: AUTO_INCREMENT=\d+)? DEFAULT CHARSET=(?:utf8mb4|utf8|latin1|binary)(?: COLLATE=[a-zA-Z0-9_]+)?(?: COMMENT='.self::LITERAL.')?\z~isD', $sql, $match)) {
                    $table = trim($match[1], chr(96));
                    if (isset($tables[$table]) || str_starts_with(strtolower($table), '__fh_')) {
                        throw new BackupFailure('restore_sql_unsupported');
                    }
                    $definitions = $this->parts($match[2]);
                    $physicalColumns = [];
                    $allColumns = [];
                    $generatedColumns = [];
                    foreach ($definitions as $definition) {
                        if (preg_match('/\A('.self::ID.')\s/', $definition, $column)) {
                            $name = trim($column[1], chr(96));
                            $allColumns[] = $name;
                            if (preg_match('/\bGENERATED\s+ALWAYS\s+AS\b/i', $definition)) {
                                $generatedColumns[] = $name;
                            } else {
                                $physicalColumns[] = $name;
                            }
                        }
                    }
                    foreach ($definitions as $definition) {
                        $this->definition($definition, $references, $physicalColumns);
                    }
                    $tables[$table] = 0;
                    $structures[$table] = ['columns' => $allColumns, 'generated' => $generatedColumns];
                } elseif (preg_match('/\AINSERT INTO ('.self::ID.')(?:\s*\(((?:'.self::ID.'\s*,\s*)*'.self::ID.')\))? VALUES\s*(.*)\z/isD', $sql, $match)) {
                    $table = trim($match[1], chr(96));
                    if (! array_key_exists($table, $tables)) {
                        throw new BackupFailure('restore_sql_unsupported');
                    }
                    $structure = $structures[$table];
                    $columns = $structure['columns'];
                    if (($match[2] ?? '') !== '') {
                        preg_match_all('/'.self::ID.'/', $match[2], $selected);
                        $columns = array_map(static fn (string $column): string => trim($column, chr(96)), $selected[0]);
                    }
                    if (count(array_unique($columns)) !== count($columns) || array_diff($columns, $structure['columns']) !== []) {
                        throw new BackupFailure('restore_sql_unsupported');
                    }
                    $physical = array_diff($columns, $structure['generated']);
                    foreach ($this->rows($match[3]) as $values) {
                        if (count($values) !== count($columns)) {
                            throw new BackupFailure('restore_sql_unsupported');
                        }
                        $tables[$table]++;
                        if ($inspectRow !== null && in_array($table, ['product_images', 'brands'], true)) {
                            $inspectRow($table, array_combine($columns, array_map($this->literalValue(...), $values)));
                        }
                        if ($structure['generated'] !== []) {
                            if ($physical === []) {
                                throw new BackupFailure('restore_sql_unsupported');
                            }
                            $checks ??= app(PrivateFiles::class)->create($destination.'.generated.jsonl');
                            foreach (array_intersect($columns, $structure['generated']) as $index => $column) {
                                if (! preg_match('/\A(?:NULL|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)\z/D', $values[$index])) {
                                    throw new BackupFailure('restore_sql_unsupported');
                                }
                            }
                            $check = json_encode(['table' => $table, 'columns' => $columns, 'values' => $values], JSON_THROW_ON_ERROR)."\n";
                            $checkBytes += strlen($check);
                            if ($checkBytes > (int) config('backup-restore.restore.max_bytes') * 4) {
                                throw new BackupFailure('restore_limit_exceeded');
                            }
                            PrivateFiles::write($checks, $check);
                            PrivateFiles::write($output, 'INSERT INTO `'.$table.'` (`'.implode('`,`', $physical).'`) VALUES ('.implode(',', array_intersect_key($values, $physical)).");\n");
                        }
                    }
                    if ($tables[$table] > 1000000) {
                        throw new BackupFailure('restore_limit_exceeded');
                    }
                    if ($structure['generated'] !== []) {
                        $tick();

                        continue;
                    }
                } elseif (preg_match('/\A(?:DROP TABLE IF EXISTS '.self::ID.'|LOCK TABLES '.self::ID.' WRITE|UNLOCK TABLES|ALTER TABLE '.self::ID.' (?:DISABLE|ENABLE) KEYS)\z/iD', $sql)) {
                    continue;
                } else {
                    throw new BackupFailure('restore_sql_unsupported');
                }
                PrivateFiles::write($output, $sql.";\n");
                $tick();
            }
            if ($tables === [] || array_diff($references, array_keys($tables)) !== []) {
                throw new BackupFailure('restore_sql_unsupported');
            }
            PrivateFiles::write($output, "SET SESSION foreign_key_checks=1;\n");
        } finally {
            fclose($output);
            if (is_resource($checks)) {
                fclose($checks);
            }
        }

        return $tables;
    }

    /** Read media identities from validated snapshot literals, never from the live database.
     * @return list<array{table: string, id: int, disk: string, path: string}>
     */
    public function mediaReferences(string $source, string $destination, Closure $tick): array
    {
        $references = [];
        $tables = $this->prepare($source, $destination, $tick, function (string $table, array $row) use (&$references): void {
            if (! isset($row['id']) || ! ctype_digit($row['id']) || (int) $row['id'] < 1
                || ($table === 'product_images' && (! isset($row['disk'], $row['path']) || $row['disk'] !== 'public'))
                || ($table === 'brands' && ! array_key_exists('icon', $row))) {
                throw new BackupFailure('unsupported_storage');
            }
            $path = $row[$table === 'brands' ? 'icon' : 'path'];
            if ($table === 'brands' && ($path === null || $path === '')) {
                return;
            }
            BackupWorkspace::assertRelative($path);
            $references[] = ['table' => $table, 'id' => (int) $row['id'], 'disk' => 'public', 'path' => $path];
            if (count($references) > (int) config('backup-restore.max_files')) {
                throw new BackupFailure('archive_invalid');
            }
        });
        if (! array_key_exists('product_images', $tables) || ! array_key_exists('brands', $tables)) {
            throw new BackupFailure('dump_incomplete');
        }

        return $references;
    }

    /** Check authenticated native generated values after strict import recomputes them.
     * @param  array<string, int>  $tables
     */
    public function verifyGenerated(string $path, PDO $database, array $tables, Closure $tick): int
    {
        $metadata = $database->query('SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,NUMERIC_SCALE,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $columns = [];
        $generatedTables = [];
        foreach ($metadata as $column) {
            $columns[$column['TABLE_NAME']][$column['COLUMN_NAME']] = $column;
            if (($column['GENERATION_EXPRESSION'] ?? '') !== '') {
                $generatedTables[$column['TABLE_NAME']] = true;
            }
        }
        $expected = array_sum(array_intersect_key($tables, $generatedTables));
        if (! file_exists($path)) {
            if ($expected !== 0) {
                throw new BackupFailure('restore_verification_failed');
            }

            return 0;
        }
        app(PrivateFiles::class)->assertPrivate($path);
        if (filesize($path) > (int) config('backup-restore.restore.max_bytes') * 4) {
            throw new BackupFailure('restore_limit_exceeded');
        }
        $input = fopen($path, 'rb');
        $verified = 0;
        try {
            $lineLimit = (int) config('backup-restore.restore.max_statement_bytes') * 4 + 8192;
            while (($line = fgets($input, $lineLimit)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new BackupFailure('restore_verification_failed');
                }
                $check = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($check) || array_keys($check) !== ['table', 'columns', 'values']
                    || ! is_string($check['table']) || ! array_key_exists($check['table'], $tables)
                    || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,63}\z/D', $check['table'])
                    || ! is_array($check['columns']) || ! array_is_list($check['columns']) || $check['columns'] === []
                    || ! is_array($check['values']) || ! array_is_list($check['values']) || count($check['columns']) !== count($check['values'])) {
                    throw new BackupFailure('restore_verification_failed');
                }
                $conditions = [];
                $bindings = [];
                foreach ($check['columns'] as $index => $column) {
                    if (! is_string($column) || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,63}\z/D', $column)
                        || ! is_string($check['values'][$index])) {
                        throw new BackupFailure('restore_verification_failed');
                    }
                    $literal = $check['values'][$index];
                    $offset = 0;
                    $this->literal($literal, $offset);
                    if ($offset !== strlen($literal)) {
                        throw new BackupFailure('restore_verification_failed');
                    }
                    $type = $columns[$check['table']][$column] ?? null;
                    if ($type === null) {
                        throw new BackupFailure('restore_verification_failed');
                    }
                    if (in_array($type['DATA_TYPE'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal'], true)) {
                        $scale = (int) ($type['NUMERIC_SCALE'] ?? 0);
                        if ($scale < 0 || $scale > 38) {
                            throw new BackupFailure('restore_sql_unsupported');
                        }
                        $conditions[] = '`'.$column.'` <=> CAST(? AS DECIMAL(65,'.$scale.'))';
                    } else {
                        $conditions[] = 'BINARY `'.$column.'` <=> BINARY ?';
                    }
                    $bindings[] = $this->literalValue($literal);
                }
                $tick();
                $query = $database->prepare('SELECT 1 FROM `'.$check['table'].'` WHERE '.implode(' AND ', $conditions).' LIMIT 1');
                $query->execute($bindings);
                if ($query->fetchColumn() === false) {
                    throw new BackupFailure('restore_verification_failed');
                }
                $verified++;
            }
        } finally {
            fclose($input);
        }
        if ($verified !== $expected) {
            throw new BackupFailure('restore_verification_failed');
        }

        return $verified;
    }

    /** Decode only already-validated dump literals for bound read-only verification queries. */
    private function literalValue(string $literal): ?string
    {
        if ($literal === 'NULL') {
            return null;
        }
        if (str_starts_with($literal, '0x')) {
            $hex = substr($literal, 2);

            return hex2bin(strlen($hex) % 2 === 0 ? $hex : '0'.$hex);
        }
        if (str_starts_with($literal, '_binary')) {
            $literal = substr($literal, 7);
        }
        if (! str_starts_with($literal, "'")) {
            return $literal;
        }
        $decoded = '';
        for ($index = 1; $index < strlen($literal) - 1; $index++) {
            $character = $literal[$index];
            if ($character === '\\') {
                $character = $literal[++$index];
                $decoded .= match ($character) {
                    '0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a",
                    '%', '_' => '\\'.$character,
                    default => $character,
                };
            } else {
                $decoded .= $character;
                if ($character === "'" && ($literal[$index + 1] ?? '') === "'") {
                    $index++;
                }
            }
        }

        return $decoded;
    }

    /** @return Generator<int, string> */
    private function statements(string $path, Closure $tick): Generator
    {
        $input = fopen($path, 'rb');
        $statement = '';
        $quote = null;
        try {
            while (($character = fgetc($input)) !== false) {
                $statement .= $character;
                if (strlen($statement) % 65536 === 0) {
                    $tick();
                }
                if (strlen($statement) > (int) config('backup-restore.restore.max_statement_bytes')) {
                    throw new BackupFailure('restore_limit_exceeded');
                }
                if ($quote !== null) {
                    if ($character === '\\' && $quote === "'") {
                        $next = fgetc($input);
                        if ($next === false) {
                            throw new BackupFailure('restore_sql_unsupported');
                        }
                        $statement .= $next;
                    } elseif ($character === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if ($character === "'" || $character === chr(96)) {
                    $quote = $character;
                } elseif ($character === '/' || $character === '-') {
                    $next = fgetc($input);
                    if (($character === '/' && $next === '*') || ($character === '-' && $next === '-')) {
                        $statement = substr($statement, 0, -1);
                        $comment = '';
                        if ($character === '-') {
                            while (($part = fgetc($input)) !== false && $part !== "\n") {
                                $comment .= $part;
                                if (strlen($comment) > 1048576) {
                                    throw new BackupFailure('restore_limit_exceeded');
                                }
                            }
                        } else {
                            while (($part = fgetc($input)) !== false) {
                                $comment .= $part;
                                if (strlen($comment) > 1048576) {
                                    throw new BackupFailure('restore_limit_exceeded');
                                }
                                if (str_ends_with($comment, '*/')) {
                                    break;
                                }
                            }
                            if (! str_ends_with($comment, '*/') || (preg_match('/\A(?:!|M!)/', $comment)
                                && ! preg_match('/\A(?:!|M!)\d*\s+SET\b.*\*\/\z/isD', $comment)
                                && ! preg_match('~\A(?:!|M!)\d+\s+ALTER TABLE '.self::ID.' (?:DISABLE|ENABLE) KEYS\s*\*/\z~iD', $comment))) {
                                throw new BackupFailure('restore_sql_unsupported');
                            }
                            if (preg_match("~\\A(?:!|M!)\\d*\\s+SET\\s+TIME_ZONE\\s*=\\s*'([^']*)'\\s*\\*/\\z~iD", $comment, $timezone)
                                && $timezone[1] !== '+00:00') {
                                throw new BackupFailure('restore_sql_unsupported');
                            }
                        }
                        $statement .= ' ';
                        $tick();
                    } elseif ($character === '-') {
                        if ($next !== false) {
                            fseek($input, -1, SEEK_CUR);
                        }
                    } else {
                        throw new BackupFailure('restore_sql_unsupported');
                    }
                } elseif ($character === ';') {
                    yield substr($statement, 0, -1);
                    $statement = '';
                } elseif ($character === '"' || $character === '\\' || ord($character) === 0) {
                    throw new BackupFailure('restore_sql_unsupported');
                }
            }
            if ($quote !== null || trim($statement) !== '') {
                throw new BackupFailure('restore_sql_unsupported');
            }
        } finally {
            fclose($input);
        }
    }

    /** @return list<string> */
    private function parts(string $body): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quoted = false;
        for ($i = 0; $i < strlen($body); $i++) {
            if ($body[$i] === '\\' && $quoted) {
                $i++;
            } elseif ($body[$i] === "'") {
                $quoted = ! $quoted;
            } elseif (! $quoted) {
                $depth += $body[$i] === '(' ? 1 : ($body[$i] === ')' ? -1 : 0);
                if ($depth < 0) {
                    throw new BackupFailure('restore_sql_unsupported');
                }
                if ($body[$i] === ',' && $depth === 0) {
                    $parts[] = trim(substr($body, $start, $i - $start));
                    $start = $i + 1;
                }
            }
        }
        if ($depth !== 0 || $quoted) {
            throw new BackupFailure('restore_sql_unsupported');
        }
        $parts[] = trim(substr($body, $start));

        return $parts;
    }

    /** @param list<string> $references
     * @param  list<string>  $physicalColumns
     */
    private function definition(string $definition, array &$references, array $physicalColumns): void
    {
        $id = self::ID;
        $columns = $id.'(?:\(\d+\))?(?: (?:ASC|DESC))?(?:\s*,\s*'.$id.'(?:\(\d+\))?(?: (?:ASC|DESC))?)*';
        $literal = self::LITERAL;
        $type = '(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|bit|boolean|bool|char|varchar|binary|varbinary|tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob|date|datetime|timestamp|time|year|json)(?:\(\d+(?:,\d+)?\))?(?: unsigned)?';
        $enum = '(?:enum|set)\('.$literal.'(?:,'.$literal.')*\)';
        $generatedType = '(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric)(?:\(\d+(?:,\d+)?\))?(?: unsigned)?';
        if (preg_match('~\A'.$id.' '.$generatedType.' GENERATED ALWAYS AS \((.*)\) (?:VIRTUAL|STORED|PERSISTENT)(?: COMMENT '.$literal.')?\z~isD', $definition, $generated)) {
            app(RecoveryGeneratedExpression::class)->validate($generated[1], $physicalColumns);

            return;
        }
        $modifier = '(?: unsigned| zerofill| NOT NULL| NULL| AUTO_INCREMENT| DEFAULT (?:NULL|-?\d+(?:\.\d+)?|'.$literal.'|current_timestamp(?:\(\d*\))?)| ON UPDATE current_timestamp(?:\(\d*\))?| CHARACTER SET [a-zA-Z0-9_]+| COLLATE [a-zA-Z0-9_]+| COMMENT '.$literal.'| CHECK \(json_valid\('.$id.'\)\))';
        if (preg_match('~\A'.$id.' (?:'.$type.'|'.$enum.')(?:'.$modifier.')*\z~iD', $definition)
            || preg_match('~\A(?:PRIMARY KEY|(?:UNIQUE )?KEY '.$id.') \('.$columns.'\)(?: USING BTREE)?\z~iD', $definition)) {
            return;
        }
        if (preg_match('~\ACONSTRAINT '.$id.' FOREIGN KEY \('.$columns.'\) REFERENCES ('.$id.') \('.$columns.'\)(?: ON (?:DELETE|UPDATE) (?:RESTRICT|CASCADE|SET NULL|NO ACTION)){0,2}\z~iD', $definition, $match)) {
            $references[] = trim($match[1], chr(96));

            return;
        }
        if (preg_match('~\ACONSTRAINT '.$id.' CHECK \((.*)\)\z~isD', $definition, $match)) {
            $expression = $match[1];
            if (preg_match('~'.$id.'\s*\(~', $expression)) {
                throw new BackupFailure('restore_sql_unsupported');
            }
            $expression = preg_replace('~'.$literal.'|'.$id.'~s', '0', $expression);
            $expression = preg_replace('/\b(?:AND|OR|NOT|IN|IS|NULL|TRUE|FALSE|JSON_VALID)\b/i', '0', $expression);
            if (preg_match('/\A[0-9\s(),<>=!+*%-]+\z/D', $expression)) {
                return;
            }
        }
        throw new BackupFailure('restore_sql_unsupported');
    }

    /** @return Generator<int, list<string>> */
    private function rows(string $values): Generator
    {
        $offset = 0;
        $rows = 0;
        do {
            $this->whitespace($values, $offset);
            if (($values[$offset++] ?? '') !== '(') {
                throw new BackupFailure('restore_sql_unsupported');
            }
            $row = [];
            do {
                $this->whitespace($values, $offset);
                $start = $offset;
                $this->literal($values, $offset);
                $row[] = substr($values, $start, $offset - $start);
                $this->whitespace($values, $offset);
                $delimiter = $values[$offset++] ?? '';
                if (! in_array($delimiter, [',', ')'], true)) {
                    throw new BackupFailure('restore_sql_unsupported');
                }
            } while ($delimiter === ',');
            if (++$rows > 1000000) {
                throw new BackupFailure('restore_limit_exceeded');
            }
            yield $row;
            $this->whitespace($values, $offset);
            if ($offset === strlen($values)) {
                return;
            }
            if (($values[$offset++] ?? '') !== ',') {
                throw new BackupFailure('restore_sql_unsupported');
            }
        } while ($offset < strlen($values));

        throw new BackupFailure('restore_sql_unsupported');
    }

    private function whitespace(string $values, int &$offset): void
    {
        while (isset($values[$offset]) && ctype_space($values[$offset])) {
            $offset++;
        }
    }

    /** Consume one literal without a recursive regex over a potentially large dump row. */
    private function literal(string $values, int &$offset): void
    {
        if (substr($values, $offset, 7) === '_binary') {
            $offset += 7;
            if (($values[$offset] ?? '') !== "'") {
                throw new BackupFailure('restore_sql_unsupported');
            }
        }
        if (($values[$offset] ?? '') === "'") {
            $offset++;
            while (isset($values[$offset])) {
                $character = $values[$offset++];
                if ($character === '\\') {
                    if (! isset($values[$offset])) {
                        throw new BackupFailure('restore_sql_unsupported');
                    }
                    $offset++;
                } elseif ($character === "'") {
                    if (($values[$offset] ?? '') === "'") {
                        $offset++;
                    } else {
                        return;
                    }
                }
            }
            throw new BackupFailure('restore_sql_unsupported');
        }
        if (! preg_match('/\G(?:NULL|0x[a-fA-F0-9]+|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,)]|\z)/D', $values, $match, 0, $offset)) {
            throw new BackupFailure('restore_sql_unsupported');
        }
        $offset += strlen($match[0]);
    }
}
