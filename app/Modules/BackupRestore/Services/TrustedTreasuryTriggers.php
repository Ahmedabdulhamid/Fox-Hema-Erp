<?php

namespace App\Modules\BackupRestore\Services;

/** Reviewed executable objects. Artifact SQL is compared, never used for installation. */
class TrustedTreasuryTriggers
{
    public const DATABASE_COLLATION = 'utf8mb4_general_ci';

    private const MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /** @return array<string, array{table: string, event: string, order: int, body: string}> */
    public function definitions(): array
    {
        return [
            'treasury_entries_no_update' => ['table' => 'treasury_entries', 'event' => 'UPDATE', 'order' => 1, 'body' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury entries are append-only'"],
            'treasury_entries_no_delete' => ['table' => 'treasury_entries', 'event' => 'DELETE', 'order' => 1, 'body' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury entries are append-only'"],
            'treasury_operations_no_update' => ['table' => 'treasury_operations', 'event' => 'UPDATE', 'order' => 1, 'body' => "BEGIN IF OLD.status = 'posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted Treasury operations are immutable'; END IF; END"],
            'treasury_operations_no_delete' => ['table' => 'treasury_operations', 'event' => 'DELETE', 'order' => 1, 'body' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury history cannot be deleted'"],
            'treasury_entries_only_before_post' => ['table' => 'treasury_entries', 'event' => 'INSERT', 'order' => 1, 'body' => "BEGIN IF (SELECT status FROM treasury_operations WHERE id = NEW.operation_id) != 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot append to posted Treasury history'; END IF; END"],
            'treasury_accounts_fixed_identity' => ['table' => 'treasury_accounts', 'event' => 'UPDATE', 'order' => 1, 'body' => "BEGIN IF NOT (OLD.code <=> NEW.code) OR NOT (OLD.type <=> NEW.type) OR NOT (OLD.ownership <=> NEW.ownership) OR NOT (OLD.location_id <=> NEW.location_id) OR NOT (OLD.currency <=> NEW.currency) OR NOT (OLD.uuid <=> NEW.uuid) OR NOT (OLD.created_by <=> NEW.created_by) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury account identity is immutable'; END IF; END"],
            'treasury_operations_fixed_code' => ['table' => 'treasury_operations', 'event' => 'UPDATE', 'order' => 2, 'body' => "BEGIN IF NOT (OLD.code <=> NEW.code) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Generated Treasury codes are immutable'; END IF; END"],
            'payment_methods_fixed_identity' => ['table' => 'payment_methods', 'event' => 'UPDATE', 'order' => 1, 'body' => "BEGIN IF NOT (OLD.code <=> NEW.code) OR NOT (OLD.type <=> NEW.type) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment method identities are immutable'; END IF; END"],
        ];
    }

    /** Fingerprint the reviewed catalog, installed dependencies and application migration sources. */
    public function releaseId(): string
    {
        $files = [];
        foreach ([...glob(base_path('database/migrations/*.php')), ...glob(app_path('Modules/*/Database/Migrations/*.php'))] as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(base_path())));
            $files[$relative] = hash_file('sha256', $path);
        }
        ksort($files);

        return 'fh-treasury-v1-'.hash('sha256', json_encode([$this->definitions(), self::MODE, self::DATABASE_COLLATION, $files, hash_file('sha256', base_path('composer.lock'))], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function profile(\PDO $database): array
    {
        $rows = $this->inventory($database);
        $this->assertInventory($rows);
        $definers = array_unique(array_column($rows, 'DEFINER'));
        if (count($definers) !== 1 || ! in_array(reset($definers), ['root@localhost', 'root@127.0.0.1'], true)) {
            throw new BackupFailure('restore_identity_mismatch');
        }

        return $this->reviewedProfile(reset($definers));
    }

    /** @return array<string, mixed> */
    public function reviewedProfile(string $definer): array
    {
        if (! in_array($definer, ['root@localhost', 'root@127.0.0.1'], true)) {
            throw new BackupFailure('restore_identity_mismatch');
        }

        return ['version' => 1, 'release_id' => $this->releaseId(), 'source_definer' => $definer, 'inventory_checksum' => $this->checksum($this->expected())];
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>|null
     */
    public function authenticate(array $manifest): ?array
    {
        $profile = $manifest['database']['trigger_profile'] ?? null;
        $treasury = array_filter($manifest['database']['migrations'] ?? [], static fn ($migration): bool => str_contains(is_array($migration) ? ($migration['migration'] ?? '') : ($migration->migration ?? ''), 'create_treasury_foundation_tables'));
        if ($profile === null) {
            if ($treasury !== [] || ($manifest['database']['database'] ?? '') === 'fox_hema_pos' || ($manifest['release_id'] ?? '') === $this->releaseId()) {
                throw new BackupFailure('restore_identity_mismatch');
            }

            return null;
        }
        if (! is_array($profile) || array_keys($profile) !== ['version', 'release_id', 'source_definer', 'inventory_checksum']
            || $profile['version'] !== 1 || $profile['release_id'] !== $this->releaseId()
            || ($manifest['release_id'] ?? null) !== $profile['release_id'] || config('backup-restore.release_id') !== $profile['release_id']
            || ! in_array($profile['source_definer'], ['root@localhost', 'root@127.0.0.1'], true)
            || $profile['inventory_checksum'] !== $this->checksum($this->expected())) {
            throw new BackupFailure('restore_identity_mismatch');
        }

        return $profile;
    }

    /** Remove only completely matched native blocks; all remaining SQL goes through RecoverySql.
     * @param  array<string, mixed>  $profile
     */
    public function prepare(string $source, string $destination, array $profile, \Closure $tick): void
    {
        $this->authenticate(['release_id' => $profile['release_id'] ?? '', 'database' => ['trigger_profile' => $profile]]);
        if (filesize($source) > (int) config('backup-restore.restore.max_bytes')) {
            throw new BackupFailure('restore_limit_exceeded');
        }
        $input = fopen($source, 'rb');
        $output = app(PrivateFiles::class)->create($destination);
        $block = null;
        $seen = [];
        $orders = [];
        $quote = null;
        $comment = false;
        try {
            while (($line = fgets($input, (int) config('backup-restore.restore.max_statement_bytes') + 2)) !== false) {
                if (strlen($line) > (int) config('backup-restore.restore.max_statement_bytes')) {
                    throw new BackupFailure('restore_limit_exceeded');
                }
                if ($quote === null && ! $comment && trim($line) === 'DELIMITER ;;') {
                    if ($block !== null) {
                        throw new BackupFailure('restore_sql_unsupported');
                    }
                    $block = '';
                } elseif ($quote === null && ! $comment && trim($line) === 'DELIMITER ;') {
                    if ($block === null) {
                        throw new BackupFailure('restore_sql_unsupported');
                    }
                    $name = $this->nativeBlock($block, $profile['source_definer']);
                    if (isset($seen[$name])) {
                        throw new BackupFailure('restore_sql_unsupported');
                    }
                    $definition = $this->definitions()[$name];
                    $group = $definition['table'].'/'.$definition['event'];
                    $orders[$group] = ($orders[$group] ?? 0) + 1;
                    if ($orders[$group] !== $definition['order']) {
                        throw new BackupFailure('restore_sql_unsupported');
                    }
                    $seen[$name] = true;
                    $block = null;
                } elseif ($block !== null) {
                    $block .= $line;
                    if (strlen($block) > 65536) {
                        throw new BackupFailure('restore_limit_exceeded');
                    }
                } else {
                    PrivateFiles::write($output, $line);
                    $this->advanceContext($line, $quote, $comment);
                }
                $tick();
            }
            if ($block !== null || count($seen) !== count($this->definitions())) {
                throw new BackupFailure('restore_sql_unsupported');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** Native directives are recognized only outside SQL literals and comments. */
    private function advanceContext(string $line, ?string &$quote, bool &$comment): void
    {
        for ($index = 0; $index < strlen($line); $index++) {
            $character = $line[$index];
            $next = $line[$index + 1] ?? '';
            if ($comment) {
                if ($character === '*' && $next === '/') {
                    $comment = false;
                    $index++;
                }
            } elseif ($quote !== null) {
                if ($character === '\\' && $quote === "'") {
                    $index++;
                } elseif ($character === $quote) {
                    if ($next === $quote) {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
            } elseif ($character === "'" || $character === '`') {
                $quote = $character;
            } elseif ($character === '/' && $next === '*') {
                $comment = true;
                $index++;
            } elseif ($character === '-' && $next === '-') {
                return;
            }
        }
    }

    private function nativeBlock(string $block, string $definer): string
    {
        $setting = '/\*!50003 SET (?:@saved_cs_client\s*=\s*@@character_set_client|@saved_cs_results\s*=\s*@@character_set_results|@saved_col_connection\s*=\s*@@collation_connection|character_set_client\s*=\s*utf8mb4|character_set_results\s*=\s*utf8mb4|collation_connection\s*=\s*utf8mb4_unicode_ci|@saved_sql_mode\s*=\s*@@sql_mode|sql_mode\s*=\s*\x27'.self::MODE.'\x27|sql_mode\s*=\s*@saved_sql_mode|character_set_client\s*=\s*@saved_cs_client|character_set_results\s*=\s*@saved_cs_results|collation_connection\s*=\s*@saved_col_connection)\s*\*/;;';
        $pattern = '~\A\s*(?:'.$setting.'\s*)*/\*!50003 CREATE\*/ /\*!50017 DEFINER=`(root)`@`(localhost|127\.0\.0\.1)`\*/ /\*!50003 TRIGGER (?<name>`[a-z_]+`|[a-z_]+) BEFORE (?<event>UPDATE|DELETE|INSERT) ON (?<table>`[a-z_]+`|[a-z_]+) FOR EACH ROW\s+(?<body>.*?)\s*\*/;;\s*(?:'.$setting.'\s*)*\z~sD';
        if (! preg_match($pattern, $block, $match) || $definer !== $match[1].'@'.$match[2]) {
            throw new BackupFailure('restore_sql_unsupported');
        }
        $name = trim($match['name'], '`');
        $definition = $this->definitions()[$name] ?? null;
        if ($definition === null || $definition['event'] !== $match['event'] || $definition['table'] !== trim($match['table'], '`') || $this->tokens($definition['body']) !== $this->tokens($match['body'])) {
            throw new BackupFailure('restore_sql_unsupported');
        }

        return $name;
    }

    /** @return list<array<string, mixed>> */
    public function inventory(\PDO $database): array
    {
        return $database->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_ORDER,ACTION_STATEMENT,DEFINER,SQL_MODE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param list<array<string, mixed>> $rows */
    public function assertInventory(array $rows, ?string $definer = null): void
    {
        if ($this->checksum($rows) !== $this->checksum($this->expected()) || ($definer !== null && array_unique(array_column($rows, 'DEFINER')) !== [$definer])) {
            throw new BackupFailure('restore_verification_failed');
        }
    }

    /** @return list<array<string, mixed>> */
    private function expected(): array
    {
        $rows = [];
        foreach ($this->definitions() as $name => $definition) {
            $rows[] = ['TRIGGER_NAME' => $name, 'EVENT_OBJECT_TABLE' => $definition['table'], 'ACTION_TIMING' => 'BEFORE', 'EVENT_MANIPULATION' => $definition['event'], 'ACTION_ORDER' => $definition['order'], 'ACTION_STATEMENT' => $definition['body'], 'SQL_MODE' => self::MODE, 'CHARACTER_SET_CLIENT' => 'utf8mb4', 'COLLATION_CONNECTION' => 'utf8mb4_unicode_ci', 'DATABASE_COLLATION' => self::DATABASE_COLLATION];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function checksum(array $rows): string
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [$row['TRIGGER_NAME'], $row['EVENT_OBJECT_TABLE'], $row['ACTION_TIMING'], $row['EVENT_MANIPULATION'], (int) $row['ACTION_ORDER'], $this->tokens($row['ACTION_STATEMENT']), $row['SQL_MODE'], $row['CHARACTER_SET_CLIENT'], $row['COLLATION_CONNECTION'], $row['DATABASE_COLLATION']];
        }
        usort($normalized, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /** Token comparison preserves literals and rejects comments and unknown syntax. @return list<string> */
    private function tokens(string $body): array
    {
        if (strlen($body) > 8192) {
            throw new BackupFailure('restore_sql_unsupported');
        }
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($body)) {
            if (! preg_match("~\\G(?:\\s+|'[^'\\\\]*'|[a-zA-Z_][a-zA-Z0-9_]*|<=>|!=|[().=;])~", $body, $match, 0, $offset)) {
                throw new BackupFailure('restore_sql_unsupported');
            }
            $offset += strlen($match[0]);
            if (trim($match[0]) !== '') {
                $tokens[] = str_starts_with($match[0], "'") ? $match[0] : strtoupper($match[0]);
            }
        }

        return $tokens;
    }

    public function sqlMode(): string
    {
        return self::MODE;
    }
}
