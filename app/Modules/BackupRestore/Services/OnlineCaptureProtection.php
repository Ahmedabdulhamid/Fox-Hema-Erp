<?php

namespace App\Modules\BackupRestore\Services;

use Closure;
use Throwable;

final class OnlineCaptureProtection implements CaptureProtection
{
    private ?MariaDbDdlLock $lock = null;

    private ?BackupLease $owner = null;

    /** Read-only configuration inspection; never acquires an instance-wide lock. */
    public function blockers(): array
    {
        $reasons = [];
        foreach (['enabled' => 'capture_disabled', 'accounts_approved' => 'capture_accounts_unapproved', 'instance_lock_approved' => 'capture_instance_unapproved'] as $setting => $reason) {
            if (config('backup-restore.capture.'.$setting) !== true) {
                $reasons[] = $reason;
            }
        }
        try {
            $this->account('guard');
            $dump = $this->account('dump');
            if ($dump['username'] === $this->account('guard')['username']) {
                throw new BackupFailure('capture_accounts_unapproved');
            }
        } catch (Throwable) {
            $reasons[] = 'capture_accounts_unapproved';
        }
        if ($reasons === []) {
            try {
                $source = app(DatabaseDumper::class)->connection();
                if (! str_starts_with($source->selectOne('SELECT VERSION() AS version')->version, '10.4.32-MariaDB')
                    || $source->select("SHOW GLOBAL VARIABLES LIKE 'wsrep_on'") !== []) {
                    $reasons[] = 'dump_incompatible';
                }
            } catch (Throwable) {
                $reasons[] = 'database_identity_mismatch';
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @return array<string, mixed> Reviewed named connections, never browser-supplied credentials. */
    public function account(string $role): array
    {
        $source = config('database.connections.'.config('database.default'), []);
        $name = config('backup-restore.capture.'.$role.'_connection');
        $account = is_string($name) ? config('database.connections.'.$name) : null;
        if (! in_array($role, ['guard', 'dump'], true) || ! is_array($account)
            || ! in_array($account['driver'] ?? null, ['mysql', 'mariadb'], true)
            || ($source['host'] ?? null) !== '127.0.0.1' || ($account['host'] ?? null) !== $source['host']
            || (int) ($account['port'] ?? 0) !== (int) ($source['port'] ?? 0)
            || ($account['database'] ?? null) !== ($source['database'] ?? null)
            || ! is_string($account['username'] ?? null) || $account['username'] === ''
            || in_array($account['username'], ['root', $source['username'] ?? null], true)
            || ! is_string($account['password'] ?? null) || $account['password'] === '') {
            throw new BackupFailure('capture_accounts_unapproved');
        }
        foreach (['url', 'unix_socket', 'read', 'write', 'options'] as $option) {
            if (! empty($account[$option])) {
                throw new BackupFailure('capture_accounts_unapproved');
            }
        }

        return $account;
    }

    public function mode(): string
    {
        return 'online_transactional';
    }

    public function capture(BackupLease $lease, Closure $capture): mixed
    {
        if ($this->owner !== null || $this->blockers() !== []) {
            throw new BackupFailure($this->blockers()[0] ?? 'capture_protection_unavailable');
        }
        $source = app(DatabaseDumper::class)->connection();
        if ($source->transactionLevel() !== 0) {
            throw new BackupFailure('capture_protection_unavailable');
        }
        $guard = $this->account('guard');
        $this->lock = new MariaDbDdlLock($guard['host'], (int) $guard['port'], $guard['username'], $guard['password']);
        $this->owner = $lease;
        try {
            return $this->lock->during(function () use ($source, $lease, $capture): mixed {
                $this->assertHeld($lease);
                $tables = $source->select('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$source->getDatabaseName(), 'BASE TABLE']);
                if ($tables === [] || collect($tables)->contains(fn ($table): bool => $table->engine !== 'InnoDB')) {
                    throw new BackupFailure('dump_incompatible');
                }
                if (config('backup-restore.release_id') !== app(TrustedTreasuryTriggers::class)->releaseId()) {
                    throw new BackupFailure('release_unconfigured');
                }
                $result = $capture();
                $this->assertHeld($lease);

                return $result;
            });
        } finally {
            $this->lock = null;
            $this->owner = null;
        }
    }

    public function assertHeld(BackupLease $lease): void
    {
        if ($this->owner !== $lease || $this->lock === null || $this->blockers() !== []) {
            throw new BackupFailure('capture_protection_unavailable');
        }
        $this->lock->assertHeld();
        app(BackupLeaseManager::class)->locked($lease, static fn (): bool => true);
    }
}
