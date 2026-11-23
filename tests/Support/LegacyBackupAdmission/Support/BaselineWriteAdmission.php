<?php

namespace Tests\Support;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\WriteAdmission;
use App\Modules\BackupRestore\Services\WriteCoordinationStore;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

class BaselineWriteAdmission extends WriteAdmission
{
    public function __construct()
    {
        if (! app()->environment('testing') || (int) DB::connection()->getConfig('port') !== 33316
            || ! str_starts_with(DB::connection()->getDatabaseName(), 'codex_public_ids_test_')) {
            throw new \LogicException('Baseline admission is restricted to the disposable performance fixture.');
        }
    }

    private int $depth = 0;

    /** @var array{uuid: string, owner: string, fence: int}|null */
    private ?array $operation = null;

    public function begin(string $kind): void
    {
        if (! config('backup-restore.write_admission_enabled')) {
            return;
        }
        if ($this->depth > 0) {
            $this->assertHeld();
            $this->depth++;

            return;
        }
        if (! in_array($kind, ['http', 'queue', 'console', 'service'], true)) {
            throw new BackupFailure('write_admission_required');
        }
        $uuid = (string) Str::uuid();
        $owner = bin2hex(random_bytes(32));
        $fence = app(WriteCoordinationStore::class)->locked(function (Connection $connection, object $barrier) use ($uuid, $owner, $kind): int {
            if ($barrier->state !== 'open') {
                throw new BackupFailure('write_barrier_active');
            }
            $now = app(WriteCoordinationStore::class)->now($connection);
            $connection->table('backup_write_operations')->insert(['uuid' => $uuid, 'owner_hash' => hash('sha256', $owner),
                'kind' => $kind, 'admitted_fence' => $barrier->fence, 'started_at' => $now,
                'heartbeat_at' => $now]);

            return (int) $barrier->fence;
        });
        $this->operation = compact('uuid', 'owner', 'fence');
        $this->depth = 1;
    }

    /** An admitted operation may finish during draining; it is never expired by a timer. */
    public function assertHeld(): void
    {
        if (! config('backup-restore.write_admission_enabled')) {
            return;
        }
        if (! $this->operation || $this->depth < 1) {
            throw new BackupFailure('write_admission_required');
        }
        app(WriteCoordinationStore::class)->locked(function (Connection $connection, object $barrier): void {
            $operation = $connection->table('backup_write_operations')->where('uuid', $this->operation['uuid'])
                ->where('owner_hash', hash('sha256', $this->operation['owner']))->first();
            if (! $operation || (int) $operation->admitted_fence !== $this->operation['fence']
                || (int) $barrier->fence < $this->operation['fence']
                || ! in_array($barrier->state, ['open', 'draining'], true)) {
                throw new BackupFailure('write_admission_required');
            }
            $connection->table('backup_write_operations')->where('uuid', $operation->uuid)
                ->update(['heartbeat_at' => $connection->raw('UTC_TIMESTAMP(6)')]);
        }, initialize: false);
    }

    public function finish(): void
    {
        if ($this->depth === 0) {
            return;
        }
        if ($this->depth > 1) {
            $this->depth--;

            return;
        }
        foreach (DB::getConnections() as $name => $connection) {
            $pdo = $connection->getRawPdo();
            if ($name !== 'backup_write_coordination' && ($connection->transactionLevel() > 0
                || ($pdo instanceof PDO && $pdo->inTransaction()))) {
                throw new BackupFailure('write_transactions_open');
            }
        }
        app(WriteCoordinationStore::class)->locked(function (Connection $connection): void {
            $connection->table('backup_write_operations')->where('uuid', $this->operation['uuid'])
                ->where('owner_hash', hash('sha256', $this->operation['owner']))->delete();
        }, initialize: false);
        $this->operation = null;
        $this->depth = 0;
    }

    public function run(string $kind, Closure $operation): mixed
    {
        $this->begin($kind);
        try {
            return $operation();
        } finally {
            $this->finish();
        }
    }
}
