<?php

namespace App\Modules\BackupRestore\Services;

use Closure;
use PDO;
use Throwable;

/** Server-wide native DDL exclusion on the tested standalone MariaDB release.
 * Owns a nonpersistent connection with no automatic reconnection. Not a capture authorization.
 */
final class MariaDbDdlLock
{
    private ?PDO $connection = null;

    private ?int $owner = null;

    private bool $held = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
    ) {}

    public function during(Closure $capture): mixed
    {
        if ($this->connection !== null || $this->host !== '127.0.0.1' || $this->port < 1 || $this->port > 65535) {
            throw new BackupFailure('capture_protection_unavailable');
        }
        $started = false;
        $failure = null;
        $result = null;
        try {
            $this->connection = new PDO('mysql:host='.$this->host.';port='.$this->port.';charset=utf8mb4', $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 3,
            ]);
            $identity = $this->connection->query('SELECT VERSION() AS version, @@port AS port, CONNECTION_ID() AS owner')->fetch(PDO::FETCH_ASSOC);
            $wsrep = $this->connection->query("SHOW GLOBAL VARIABLES LIKE 'wsrep_on'")->fetch(PDO::FETCH_ASSOC);
            if (! str_starts_with($identity['version'], '10.4.32-MariaDB') || (int) $identity['port'] !== $this->port || $wsrep !== false) {
                throw new BackupFailure('capture_protection_unavailable');
            }
            $this->owner = (int) $identity['owner'];
            $this->connection->exec('SET SESSION lock_wait_timeout=3');
            $this->connection->exec('SET SESSION wait_timeout=15');
            $this->connection->exec('BACKUP STAGE START');
            $started = true;
            $this->connection->exec('BACKUP STAGE BLOCK_DDL');
            $this->held = true;
            $this->assertHeld();
            $result = $capture();
            $this->assertHeld();
        } catch (Throwable $error) {
            $failure = BackupFailure::sanitized($error, 'capture_protection_unavailable');
        } finally {
            if ($started) {
                try {
                    $this->connection->exec('BACKUP STAGE END');
                } catch (Throwable) {
                    $failure ??= new BackupFailure('capture_protection_unavailable');
                }
            }
            $this->held = false;
            $this->owner = null;
            $this->connection = null;
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    public function assertHeld(): void
    {
        try {
            if (! $this->held || $this->connection === null || $this->owner !== (int) $this->connection->query('SELECT CONNECTION_ID()')->fetchColumn()) {
                throw new BackupFailure('capture_protection_unavailable');
            }
        } catch (Throwable) {
            throw new BackupFailure('capture_protection_unavailable');
        }
    }
}
