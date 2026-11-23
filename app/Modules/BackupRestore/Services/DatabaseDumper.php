<?php

namespace App\Modules\BackupRestore\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseDumper
{
    public function connection(): Connection
    {
        $connection = DB::connection();
        $name = $connection->getDatabaseName();
        $isolated = app()->environment('testing') && str_starts_with($name, 'codex_public_ids_test_');
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) || (! $isolated && $name !== config('backup-restore.database'))
            || $name === 'e_commerce' || ! preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) || ! is_string($connection->getConfig('host'))
            || $connection->getConfig('unix_socket') || $connection->getConfig('read') || $connection->getConfig('write') || $connection->getConfig('options')) {
            throw new BackupFailure('database_identity_mismatch');
        }
        $actual = $connection->selectOne('SELECT DATABASE() AS db, @@port AS port');
        if ($actual->db !== $name || (int) $actual->port !== (int) $connection->getConfig('port')) {
            throw new BackupFailure('database_identity_mismatch');
        }

        return $connection;
    }

    /** @return array{binary: string, family: string, version: string} */
    public function client(): array
    {
        $binary = config('backup-restore.dump_binary');
        if (! is_string($binary) || ! is_file($binary) || is_link($binary) || realpath($binary) !== $binary && ! preg_match('~\A(?:[a-zA-Z]:[\\\\/]|/)~', $binary)) {
            throw new BackupFailure('dump_binary_unavailable');
        }
        try {
            $help = $this->process([$binary, '--no-defaults', '--help']);
            $help->setTimeout(10)->run();
            if (! $help->isSuccessful()) {
                throw new BackupFailure('dump_binary_unavailable');
            }
            $server = (string) $this->connection()->selectOne('SELECT VERSION() AS version')->version;
            $helpOutput = $help->getOutput();
            $client = trim(strtok($helpOutput, "\r\n") ?: '');
            $family = str_contains($server, 'MariaDB') ? 'mariadb' : 'mysql';
            if (($family === 'mariadb') !== str_contains($client, 'MariaDB') || ! preg_match('/\b(?:Distrib|Ver)\s+(\d+\.\d+)\.\d+/', $client, $clientVersion)
                || ! preg_match('/(?:\A|5\.5\.5-)(\d+\.\d+)\.\d+/', $server, $serverVersion) || $clientVersion[1] !== $serverVersion[1]) {
                throw new BackupFailure('dump_incompatible');
            }
            foreach (['single-transaction', 'routines', 'events', 'triggers', 'result-file', 'hex-blob', 'dump-date'] as $flag) {
                if (! str_contains($helpOutput, $flag)) {
                    throw new BackupFailure('dump_incompatible');
                }
            }

            return ['binary' => $binary, 'family' => $family, 'version' => trim($client)];
        } catch (Throwable $failure) {
            throw BackupFailure::sanitized($failure, 'dump_binary_unavailable');
        }
    }

    /** @return array{path: string, size: int, sha256: string, database: string, server: string, schema_checksum: string, trigger_profile: array<string, mixed>, media_references: list<array{table: string, id: int, disk: string, path: string}>, migrations: array, client: string} */
    public function dump(BackupWorkspace $workspace, BackupLease $lease): array
    {
        app(CaptureProtection::class)->assertHeld($lease);
        $connection = $this->connection();
        $client = $this->client();
        $schema = $this->schema($connection);
        $catalog = app(TrustedTreasuryTriggers::class);
        $triggerProfile = $catalog->profile($connection->getPdo());
        $catalog->authenticate(['release_id' => config('backup-restore.release_id'), 'database' => ['trigger_profile' => $triggerProfile]]);
        $tables = $connection->select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME', [$connection->getDatabaseName(), 'BASE TABLE']);
        $options = $workspace->file('client.cnf');
        $output = $workspace->file('database.sql');
        $process = null;
        try {
            $credentials = app(CaptureProtection::class) instanceof OnlineCaptureProtection
                ? app(OnlineCaptureProtection::class)->account('dump') : $connection->getConfig();
            $this->defaults($credentials, $options);
            $stream = app(PrivateFiles::class)->create($output);
            fclose($stream);
            $arguments = [$client['binary'], '--defaults-file='.$options, '--single-transaction', '--quick', '--skip-lock-tables', '--routines', '--events', '--triggers', '--hex-blob', '--comments', '--dump-date', '--tz-utc', '--default-character-set=utf8mb4', '--result-file='.$output];
            if ($client['family'] === 'mysql') {
                $arguments = [...$arguments, '--set-gtid-purged=OFF', '--column-statistics=0', '--no-tablespaces'];
            }
            $arguments[] = $connection->getDatabaseName();
            array_splice($arguments, 2, 0, ['--host='.$connection->getConfig('host'), '--port='.(int) $connection->getConfig('port'), '--user='.$credentials['username'], '--protocol=tcp']);
            $process = $this->process($arguments);
            $process->setTimeout((int) config('backup-restore.dump_timeout'))->disableOutput()->start();
            $nextHeartbeat = 0.0;
            while ($process->isRunning()) {
                $process->checkTimeout();
                if (microtime(true) >= $nextHeartbeat) {
                    app(CaptureProtection::class)->assertHeld($lease);
                    app(BackupLeaseManager::class)->heartbeat($lease);
                    $nextHeartbeat = microtime(true) + 5;
                }
                usleep(100000);
            }
            if (! $process->isSuccessful()) {
                throw new BackupFailure('dump_failed');
            }
            app(CaptureProtection::class)->assertHeld($lease);
            $this->complete($output, array_map(fn ($table): string => $table->name, $tables));
            $nextCheck = 0.0;
            $tick = function () use ($lease, &$nextCheck): void {
                if (microtime(true) >= $nextCheck) {
                    app(CaptureProtection::class)->assertHeld($lease);
                    $nextCheck = microtime(true) + 2;
                }
            };
            $source = $workspace->file('media-source.sql');
            $validated = $workspace->file('media-validated.sql');
            try {
                $catalog->prepare($output, $source, $triggerProfile, $tick);
                $mediaReferences = app(RecoverySql::class)->mediaReferences($source, $validated, $tick);
            } finally {
                foreach ([$source, $validated, $validated.'.generated.jsonl'] as $temporary) {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            if ($schema !== $this->schema($connection) || $triggerProfile !== $catalog->profile($connection->getPdo())) {
                throw new BackupFailure('dump_incomplete');
            }

            return ['path' => $output, 'size' => filesize($output), 'sha256' => hash_file('sha256', $output),
                'database' => $connection->getDatabaseName(), 'server' => $connection->selectOne('SELECT VERSION() AS version')->version,
                'schema_checksum' => $schema, 'trigger_profile' => $triggerProfile, 'media_references' => $mediaReferences, 'migrations' => $connection->table('migrations')->orderBy('migration')->get(['migration', 'batch'])->toArray(), 'client' => $client['version']];
        } catch (Throwable $failure) {
            if ($process !== null && $process->isStarted()) {
                try {
                    if ($process->isRunning()) {
                        $process->stop(1);
                    }
                    if (! $process->isTerminated()) {
                        throw new BackupFailure('capture_cleanup_unverified');
                    }
                } catch (Throwable) {
                    throw new BackupFailure('capture_cleanup_unverified');
                }
            }
            if (is_file($output)) {
                unlink($output);
            }
            throw BackupFailure::sanitized($failure, 'dump_failed');
        } finally {
            if (is_file($options)) {
                unlink($options);
            }
        }
    }

    private function schema(Connection $connection): string
    {
        $columns = $connection->select('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME,ORDINAL_POSITION', [$connection->getDatabaseName()]);
        $indexes = $connection->select('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX', [$connection->getDatabaseName()]);
        // Indexes and foreign keys are fingerprinted separately; this query covers CHECK clauses.
        $constraints = $connection->select('SELECT TABLE_NAME,CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? ORDER BY TABLE_NAME,CONSTRAINT_NAME', [$connection->getDatabaseName()]);
        $references = $connection->select('SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? ORDER BY TABLE_NAME,CONSTRAINT_NAME', [$connection->getDatabaseName()]);

        return hash('sha256', json_encode([$columns, $indexes, $constraints, $references], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $configuration */
    private function defaults(#[\SensitiveParameter] array $configuration, string $path): void
    {
        $quote = static fn (string $value): string => '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '\\r', '\\n'], $value).'"';
        $stream = app(PrivateFiles::class)->create($path);
        try {
            PrivateFiles::write($stream, "[client]\nprotocol=tcp\nhost=".$quote($configuration['host'])."\nport=".(int) $configuration['port']."\nuser=".$quote($configuration['username'])."\npassword=".$quote($configuration['password'] ?? '')."\n");
        } finally {
            fclose($stream);
        }
    }

    /** @param list<string> $tables */
    protected function process(array $arguments): Process
    {
        return new Process($arguments);
    }

    private function complete(string $path, array $tables): void
    {
        $stream = fopen($path, 'rb');
        $found = [];
        try {
            while (($line = fgets($stream, 1024 * 1024)) !== false) {
                if (preg_match('/\ACREATE TABLE `((?:``|[^`])+)`/', $line, $match)) {
                    $found[] = str_replace('``', '`', $match[1]);
                }
            }
            fseek($stream, -min(2048, filesize($path)), SEEK_END);
            $tail = stream_get_contents($stream);
            if (array_diff($tables, $found) !== [] || ! str_contains($tail, '-- Dump completed on ')) {
                throw new BackupFailure('dump_incomplete');
            }
        } finally {
            fclose($stream);
        }
    }
}
