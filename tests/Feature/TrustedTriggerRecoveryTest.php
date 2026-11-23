<?php

namespace Tests\Feature;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\PrivateFiles;
use App\Modules\BackupRestore\Services\RecoveryDatabase;
use App\Modules\BackupRestore\Services\RecoverySql;
use App\Modules\BackupRestore\Services\RecoveryWorkspace;
use App\Modules\BackupRestore\Services\TrustedTreasuryTriggers;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\BackupFileEnvironment;
use Tests\TestCase;

class TrustedTriggerRecoveryTest extends TestCase
{
    use BackupFileEnvironment;

    #[DataProvider('installationModes')]
    public function test_native_migration_triggers_reconstruct_with_matching_invariants_and_safe_quarantine(bool $interrupt): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $databaseName = DB::connection()->getDatabaseName();
        $this->assertMatchesRegularExpression('/\Acodex_public_ids_test_treasury_[0-9]+_[a-f0-9]{8}\z/D', $databaseName);
        $this->assertSame([], DB::connection()->getSchemaBuilder()->getTableListing(DB::connection()->getDatabaseName(), false));
        DB::statement('ALTER DATABASE `'.$databaseName.'` CHARACTER SET utf8mb4 COLLATE '.TrustedTreasuryTriggers::DATABASE_COLLATION);
        $this->assertSame(TrustedTreasuryTriggers::DATABASE_COLLATION, DB::selectOne('SELECT DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->collation);
        $this->setUpBackupFiles();
        $catalog = app(TrustedTreasuryTriggers::class);
        config(['backup-restore.release_id' => $catalog->releaseId(), 'backup-restore.restore.root' => $this->backupTestRoot.'/recovery', 'backup-restore.restore.client_binary' => 'C:/xampp/mysql/bin/mysql.exe']);
        $operation = bin2hex(random_bytes(16));
        $owner = bin2hex(random_bytes(32));
        $workspace = RecoveryWorkspace::create($operation, $owner);
        fclose(app(PrivateFiles::class)->create(dirname($workspace->files->path).'/recovery.lock'));
        $target = 'fh_recovery_'.$operation;
        $settings = ['host' => '127.0.0.1', 'port' => 33316, 'username' => 'root', 'password' => ''];
        $created = false;
        try {
            foreach (['users', 'locations', 'payments'] as $table) {
                DB::statement('CREATE TABLE `'.$table.'` (id bigint unsigned PRIMARY KEY) ENGINE=InnoDB');
            }
            DB::statement("CREATE TABLE payment_methods (id int PRIMARY KEY, code varchar(40), type enum('cash','card','bank_transfer','wallet','other'), name varchar(40)) ENGINE=InnoDB");
            (require app_path('Modules/Treasury/Database/Migrations/2026_10_25_020227_create_treasury_foundation_tables.php'))->up();
            DB::statement('ALTER TABLE treasury_operations ADD code varchar(40)');
            foreach (['2026_10_25_204315_add_automatic_financial_document_numbering.php', '2026_10_26_032802_add_payment_method_document_numbering.php'] as $migration) {
                preg_match('/DB::unprepared\("(CREATE TRIGGER.*?)"\);/s', file_get_contents(app_path('Modules/Treasury/Database/Migrations/'.$migration)), $match);
                DB::unprepared($match[1]);
            }
            DB::table('users')->insert(['id' => 1]);
            DB::table('payment_methods')->insert(['id' => 1, 'code' => 'PM', 'type' => 'cash', 'name' => 'Cash']);
            DB::table('treasury_accounts')->insert(['id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111', 'code' => 'TA', 'name' => 'Bank', 'type' => 'bank', 'ownership' => 'central', 'currency' => 'EGP', 'created_by' => 1, 'updated_by' => 1]);
            DB::table('treasury_operations')->insert(['id' => 1, 'uuid' => '22222222-2222-4222-8222-222222222222', 'idempotency_key' => '33333333-3333-4333-8333-333333333333', 'payload_fingerprint' => str_repeat('a', 64), 'type' => 'cash_deposit', 'account_id' => 1, 'amount' => '12.3456', 'currency' => 'EGP', 'effective_date' => '2026-11-14', 'source_type' => 'external_capital', 'source_reference' => 'synthetic', 'reason' => 'Synthetic', 'created_by' => 1, 'code' => 'TCR']);
            DB::table('treasury_entries')->insert(['id' => 1, 'operation_id' => 1, 'account_id' => 1, 'direction' => 'in', 'amount' => '12.3456', 'currency' => 'EGP', 'ownership' => 'central']);
            DB::table('treasury_operations')->where('id', 1)->update(['status' => 'posted', 'posted_by' => 1, 'posted_at' => '2026-11-14 12:00:00']);
            DB::statement("INSERT INTO treasury_operations (id,uuid,idempotency_key,payload_fingerprint,type,status,account_id,amount,currency,effective_date,source_type,source_reference,reason,created_by,code) SELECT 2,'44444444-4444-4444-8444-444444444444','55555555-5555-4555-8555-555555555555',payload_fingerprint,type,'draft',account_id,amount,currency,effective_date,source_type,'synthetic-draft',reason,created_by,'DRAFT' FROM treasury_operations WHERE id=1");
            $source = DB::connection()->getPdo();
            $sourceInventory = $catalog->inventory($source);
            $this->assertCount(8, $sourceInventory);
            $definers = array_values(array_unique(array_column($sourceInventory, 'DEFINER')));
            $this->assertCount(1, $definers);
            $this->assertContains($definers[0], ['root@localhost', 'root@127.0.0.1']);
            foreach ($sourceInventory as $trigger) {
                $this->assertSame($catalog->sqlMode(), $trigger['SQL_MODE']);
                $this->assertSame('utf8mb4', $trigger['CHARACTER_SET_CLIENT']);
                $this->assertSame('utf8mb4_unicode_ci', $trigger['COLLATION_CONNECTION']);
                $this->assertSame(TrustedTreasuryTriggers::DATABASE_COLLATION, $trigger['DATABASE_COLLATION']);
            }
            $untrustedInventory = $sourceInventory;
            $untrustedInventory[0]['DATABASE_COLLATION'] = 'utf8mb4_unicode_ci';
            try {
                $catalog->assertInventory($untrustedInventory);
                $this->fail('A different database collation must not match the reviewed trigger inventory.');
            } catch (BackupFailure $failure) {
                $this->assertSame('restore_verification_failed', $failure->getMessage());
            }
            $profile = $catalog->profile($source);
            $native = $workspace->files->file('native.sql');
            (new Process(['C:/xampp/mysql/bin/mysqldump.exe', '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', '--port=33316', '--user=root', '--single-transaction', '--skip-lock-tables', '--hex-blob', '--tz-utc', '--triggers', '--result-file='.$native, DB::connection()->getDatabaseName()]))->setTimeout(15)->mustRun();
            file_put_contents(storage_path('framework/testing/phase6c2/native-trigger-fixture.sql'), file_get_contents($native));
            $catalog->prepare($native, $workspace->files->file('trusted-tables.sql'), $profile, static fn () => null);
            app(RecoverySql::class)->prepare($workspace->files->file('trusted-tables.sql'), $workspace->files->file('import.sql'), static fn () => null);
            $database = app(RecoveryDatabase::class)->import($settings, $target, $workspace, $owner, static fn () => null);
            $created = true;
            $this->assertSame(TrustedTreasuryTriggers::DATABASE_COLLATION, $database->query('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->fetchColumn());
            $this->assertSame('12.3456', $database->query('SELECT amount FROM treasury_entries')->fetchColumn());
            try {
                app(RecoveryDatabase::class)->installTriggers($database, $target, $workspace, str_repeat('f', 64), $profile, static fn () => null);
                $this->fail('A forged database owner must not provision triggers or accounts.');
            } catch (BackupFailure $failure) {
                $this->assertSame('restore_target_unsafe', $failure->getMessage());
            }
            $calls = 0;
            try {
                app(RecoveryDatabase::class)->installTriggers($database, $target, $workspace, $owner, $profile, static function () use ($interrupt, &$calls): void {
                    if ($interrupt && ++$calls === 2) {
                        throw new BackupFailure('restore_limit_exceeded');
                    }
                });
                $this->assertFalse($interrupt);
            } catch (BackupFailure $failure) {
                if (! $interrupt) {
                    throw $failure;
                }
                $this->assertSame('restore_limit_exceeded', $failure->getMessage());
                $this->assertCount(1, $catalog->inventory($database));
                $this->assertStringContainsString('trigger_installation_quarantined', file_get_contents($workspace->files->file('journal.jsonl')));
                $this->assertSame(1, (int) $database->query('SELECT COUNT(*) FROM treasury_entries')->fetchColumn());
                try {
                    $catalog->assertInventory($catalog->inventory($database));
                    $this->fail('Partial inventory must not verify.');
                } catch (BackupFailure $verification) {
                    $this->assertSame('restore_verification_failed', $verification->getMessage());
                }

                return;
            }
            $catalog->assertInventory($catalog->inventory($database), app(RecoveryDatabase::class)->triggerDefiner($owner).'@127.0.0.1');
            $this->assertCount(8, $catalog->inventory($database));
            $results = self::invariantResults($database);
            $this->assertSame(self::invariantResults($source), $results);
            foreach ($results as $result) {
                $this->assertSame(['45000', 1644], array_slice($result, 0, 2));
            }
            $this->assertStringContainsString('Generated Treasury codes are immutable', end($results)[2]);
            app(RecoveryDatabase::class)->verifyTriggerDefiner($database, $target, $owner);
        } finally {
            if ($created) {
                app(RecoveryDatabase::class)->cleanup($settings, $target, $workspace, $owner);
            }
            $this->removeBackupTestDirectory($this->backupTestRoot);
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (['treasury_entries', 'treasury_operations', 'treasury_accounts', 'payment_methods', 'payments', 'locations', 'users'] as $table) {
                DB::statement('DROP TABLE IF EXISTS `'.$table.'`');
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** Each attempt is rolled back, including unexpectedly accepted writes.
     * @return list<array{string, int, string}>
     */
    public static function invariantResults(PDO $database): array
    {
        $entry = (int) $database->query('SELECT id FROM treasury_entries ORDER BY id LIMIT 1')->fetchColumn();
        $operation = (int) $database->query("SELECT id FROM treasury_operations WHERE status='posted' ORDER BY id LIMIT 1")->fetchColumn();
        $draft = (int) $database->query("SELECT id FROM treasury_operations WHERE status='draft' ORDER BY id LIMIT 1")->fetchColumn();
        $account = (int) $database->query('SELECT id FROM treasury_accounts ORDER BY id LIMIT 1')->fetchColumn();
        $method = (int) $database->query('SELECT id FROM payment_methods ORDER BY id LIMIT 1')->fetchColumn();
        $statements = [
            'UPDATE treasury_entries SET amount=13 WHERE id='.$entry, 'DELETE FROM treasury_entries WHERE id='.$entry,
            "UPDATE treasury_operations SET reason='changed' WHERE id=".$operation, 'DELETE FROM treasury_operations WHERE id='.$operation,
            "UPDATE treasury_accounts SET code='changed' WHERE id=".$account, "UPDATE treasury_accounts SET uuid='66666666-6666-4666-8666-666666666666' WHERE id=".$account,
            "UPDATE payment_methods SET code='changed' WHERE id=".$method, "UPDATE payment_methods SET type='bank_transfer' WHERE id=".$method,
            'INSERT INTO treasury_entries (operation_id,account_id,direction,amount,currency,ownership) VALUES ('.$operation.','.$account.",'in',1,'EGP','central')",
            "UPDATE treasury_operations SET code='changed' WHERE id=".$draft,
        ];
        $results = [];
        foreach ($statements as $sql) {
            $database->beginTransaction();
            try {
                $database->exec($sql);
                $results[] = ['accepted', 0, ''];
            } catch (PDOException $exception) {
                $results[] = [$exception->errorInfo[0], (int) $exception->errorInfo[1], $exception->errorInfo[2]];
            } finally {
                $database->rollBack();
            }
        }

        return $results;
    }

    public static function installationModes(): array
    {
        return [[false], [true]];
    }
}
