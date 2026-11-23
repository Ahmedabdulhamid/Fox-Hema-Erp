<?php

namespace Tests\Unit;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupQueueIsolation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackupQueueIsolationTest extends TestCase
{
    #[DataProvider('routes')]
    public function test_ordinary_worker_routes_cannot_select_control_queues(?string $connection, string $queues, bool $allowed): void
    {
        config(['backup-restore.queue_connection' => 'backup_database', 'backup-restore.queue' => 'backups']);
        if (! $allowed) {
            $this->expectException(BackupFailure::class);
        }
        app(BackupQueueIsolation::class)->assertOrdinaryRoute($connection, $queues);
        $this->assertTrue($allowed);
    }

    #[DataProvider('storageVariants')]
    public function test_storage_aliases_and_unknown_configuration_never_claim_supervised_isolation(string $variant, ?bool $shared): void
    {
        $database = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 33316, 'database' => 'codex_public_ids_test_queue', 'prefix' => '', 'password' => 'secret-never-output'];
        config(['database.default' => 'mysql', 'database.connections.mysql' => $database, 'database.connections.alias' => $database,
            'backup-restore.queue_connection' => 'backup_database', 'queue.failed.driver' => 'database-uuids',
            'queue.connections.database' => ['driver' => 'database', 'connection' => null, 'table' => 'jobs'],
            'queue.connections.backup_database' => ['driver' => 'database', 'connection' => 'alias', 'table' => 'jobs']]);
        if ($variant === 'different_table') {
            config(['queue.connections.backup_database.table' => 'backup_jobs']);
        } elseif ($variant === 'url') {
            config(['database.connections.alias.url' => 'mysql://redacted.invalid/unknown']);
        } elseif ($variant === 'read_write') {
            config(['database.connections.alias.read' => ['host' => 'unverified']]);
        } elseif ($variant === 'host_alias') {
            config(['database.connections.alias.host' => 'localhost']);
        } elseif ($variant === 'different_driver') {
            config(['queue.connections.backup_database.driver' => 'redis']);
        } elseif ($variant === 'prefix_alias') {
            config(['database.connections.mysql.prefix' => 'erp_', 'queue.connections.backup_database.table' => 'erp_jobs']);
        } elseif ($variant === 'invalid_prefix') {
            config(['database.connections.alias.prefix' => ['unverified']]);
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $report = app(BackupQueueIsolation::class)->inspect();
        $this->assertSame($shared, $report['shared_reservation_backend']);
        $this->assertSame('blocked', $report['status']);
        $this->assertTrue($report['shared_failure_storage']);
        $this->assertSame(0, $queries);
        $this->assertStringNotContainsString('secret-never-output', json_encode($report));
    }

    public static function routes(): array
    {
        return [[null, 'default', true], ['database', 'reporting,default', true], ['alias', 'backups', false],
            ['database', 'default,backups', false], ['database', 'backup-restores', false], ['backup_database', 'default', false],
            ['alias', ' BACKUPS ', false], [null, '*', false], [null, '', false], [null, 'default,', false]];
    }

    public static function storageVariants(): array
    {
        return [['alias', true], ['different_table', false], ['url', null], ['read_write', null], ['host_alias', null], ['different_driver', null], ['prefix_alias', true], ['invalid_prefix', null]];
    }
}
