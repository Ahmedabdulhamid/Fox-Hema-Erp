<?php

namespace App\Modules\BackupRestore\Services;

use Closure;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RestoreVerifier
{
    /** @param array<string, mixed> $manifest
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    public function verify(PDO $database, string $target, RecoveryWorkspace $workspace, array $manifest, array $counts, Closure $tick): array
    {
        app(RecoveryDatabase::class)->assertTarget($target, $workspace);
        if ($database->query('SELECT DATABASE()')->fetchColumn() !== $target) {
            throw new BackupFailure('restore_target_unsafe');
        }
        $actual = $database->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME <> '__fh_recovery_owner' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        $workspace->record('verification_checkpoint', ['check' => 'schema_and_declared_counts']);
        $expected = array_keys($counts);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if (isset($manifest['database']['row_counts'])) {
            $declaredCounts = $manifest['database']['row_counts'];
            $actualCounts = $counts;
            if (! is_array($declaredCounts)) {
                throw new BackupFailure('restore_verification_failed');
            }
            ksort($declaredCounts);
            ksort($actualCounts);
            if ($declaredCounts !== $actualCounts) {
                throw new BackupFailure('restore_verification_failed');
            }
        }
        if ($actual !== $expected || ! hash_equals($manifest['database']['schema_checksum'], $this->schemaChecksum($database))) {
            throw new BackupFailure('restore_verification_failed');
        }
        $workspace->record('verification_checkpoint', ['check' => 'unsupported_objects']);
        $catalog = app(TrustedTreasuryTriggers::class);
        $profile = $catalog->authenticate($manifest);
        $triggers = $catalog->inventory($database);
        if ($profile === null && $triggers !== []) {
            throw new BackupFailure('restore_verification_failed');
        }
        if ($profile !== null) {
            $owner = json_decode(file_get_contents($workspace->files->file('owner.json')), true, flags: JSON_THROW_ON_ERROR)['owner'];
            $catalog->assertInventory($triggers, app(RecoveryDatabase::class)->triggerDefiner($owner).'@127.0.0.1');
            app(RecoveryDatabase::class)->verifyTriggerDefiner($database, $target, $owner);
        }
        foreach (['ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA', 'VIEWS' => 'TABLE_SCHEMA'] as $table => $schema) {
            if ((int) $database->query('SELECT COUNT(*) FROM information_schema.'.$table.' WHERE '.$schema.'=DATABASE()')->fetchColumn() !== 0) {
                throw new BackupFailure('restore_verification_failed');
            }
        }
        $workspace->record('verification_checkpoint', ['check' => 'table_health_and_counts']);
        foreach ($counts as $table => $count) {
            $quoted = $this->quote($table);
            if ((int) $database->query('SELECT COUNT(*) FROM '.$quoted)->fetchColumn() !== $count) {
                throw new BackupFailure('restore_verification_failed');
            }
            if (isset($manifest['database']['row_counts'][$table]) && $manifest['database']['row_counts'][$table] !== $count) {
                throw new BackupFailure('restore_verification_failed');
            }
            $check = $database->query('CHECK TABLE '.$quoted)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($check as $row) {
                if ($row['Msg_type'] === 'error' || ($row['Msg_type'] === 'status' && $row['Msg_text'] !== 'OK')) {
                    throw new BackupFailure('restore_verification_failed');
                }
            }
            $tick();
        }
        $workspace->record('verification_checkpoint', ['check' => 'foreign_keys']);
        $foreignKeys = $database->query('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($foreignKeys as $key) {
            if ($key['REFERENCED_TABLE_SCHEMA'] !== $target) {
                throw new BackupFailure('restore_target_unsafe');
            }
            $groups[$key['TABLE_NAME'].'/'.$key['CONSTRAINT_NAME']][] = $key;
        }
        foreach ($groups as $keys) {
            $joins = [];
            $present = [];
            foreach ($keys as $key) {
                $child = 'c.'.$this->quote($key['COLUMN_NAME']);
                $joins[] = $child.'=p.'.$this->quote($key['REFERENCED_COLUMN_NAME']);
                $present[] = $child.' IS NOT NULL';
            }
            $first = $keys[0];
            $sql = 'SELECT COUNT(*) FROM '.$this->quote($first['TABLE_NAME']).' c LEFT JOIN '.$this->quote($first['REFERENCED_TABLE_NAME']).' p ON '.implode(' AND ', $joins).' WHERE '.implode(' AND ', $present).' AND p.'.$this->quote($first['REFERENCED_COLUMN_NAME']).' IS NULL';
            if ((int) $database->query($sql)->fetchColumn() !== 0) {
                throw new BackupFailure('restore_verification_failed');
            }
            $tick();
        }
        $workspace->record('verification_checkpoint', ['check' => 'media']);
        $files = [];
        foreach ($manifest['files'] as $file) {
            if ($file['path'] === 'database/database.sql') {
                continue;
            }
            $path = $workspace->files->file('recovered/'.$file['path']);
            if (is_link($path) || ! is_file($path) || filesize($path) !== $file['size'] || ! hash_equals($file['sha256'], hash_file('sha256', $path))) {
                throw new BackupFailure('checksum_mismatch');
            }
            $files[$file['path']] = true;
            $tick();
        }
        $recovered = $workspace->files->path.'/recovered';
        $seen = 0;
        if (is_dir($recovered)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($recovered, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if ($entry->isLink()) {
                    throw new BackupFailure('unsafe_path');
                }
                if ($entry->isFile()) {
                    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($recovered) + 1));
                    if (! isset($files[$relative])) {
                        throw new BackupFailure('archive_invalid');
                    }
                    $seen++;
                }
            }
        }
        if ($seen !== count($files)) {
            throw new BackupFailure('archive_invalid');
        }
        foreach (['product_images' => ['path', 'disk'], 'brands' => ['icon']] as $table => $columns) {
            if (! array_key_exists($table, $counts)) {
                throw new BackupFailure('restore_verification_failed');
            }
            $where = $table === 'brands' ? " WHERE icon IS NOT NULL AND icon <> ''" : '';
            foreach ($database->query('SELECT '.implode(',', array_map($this->quote(...), $columns)).' FROM '.$this->quote($table).$where) as $row) {
                $path = $row[$columns[0]];
                BackupWorkspace::assertRelative($path);
                if (($table === 'product_images' && $row['disk'] !== 'public') || ! isset($files['files/public/'.$path])) {
                    throw new BackupFailure('missing_media');
                }
            }
        }
        $workspace->record('verification_checkpoint', ['check' => 'migration_history']);
        if (isset($counts['migrations'])) {
            $migrations = $database->query('SELECT migration,batch FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_OBJ);
            if (json_encode($migrations, JSON_THROW_ON_ERROR) !== json_encode($manifest['database']['migrations'], JSON_THROW_ON_ERROR)) {
                throw new BackupFailure('restore_verification_failed');
            }
        }

        $workspace->record('verification_checkpoint', ['check' => 'generated_values']);
        $generatedRows = app(RecoverySql::class)->verifyGenerated($workspace->files->file('import.sql.generated.jsonl'), $database, $counts, $tick);

        return ['archive_integrity_verified' => true, 'isolated_restoration_completed' => true, 'isolated_restoration_verified' => true,
            'production_recovery_ready' => false, 'tables_verified' => count($counts), 'files_verified' => count($files),
            'rows_verified' => array_sum($counts), 'row_count_source' => isset($manifest['database']['row_counts']) ? 'authenticated_manifest_and_dump' : 'authenticated_dump',
            'foreign_keys_verified' => count($groups), 'generated_rows_verified' => $generatedRows, 'trusted_triggers_verified' => count($triggers), 'source_release' => $manifest['release_id']];
    }

    public function schemaChecksum(PDO $database): string
    {
        $columns = $database->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME <> '__fh_recovery_owner' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_OBJ);
        $indexes = $database->query("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME <> '__fh_recovery_owner' ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_OBJ);
        $constraints = $database->query('SELECT TABLE_NAME,CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME')->fetchAll(PDO::FETCH_OBJ);
        $references = $database->query("SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME <> '__fh_recovery_owner' ORDER BY TABLE_NAME,CONSTRAINT_NAME")->fetchAll(PDO::FETCH_OBJ);

        return hash('sha256', json_encode([$columns, $indexes, $constraints, $references], JSON_THROW_ON_ERROR));
    }

    private function quote(string $identifier): string
    {
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,63}\z/D', $identifier)) {
            throw new BackupFailure('restore_sql_unsupported');
        }

        return chr(96).$identifier.chr(96);
    }
}
