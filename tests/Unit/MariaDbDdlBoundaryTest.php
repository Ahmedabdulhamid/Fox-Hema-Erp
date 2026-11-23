<?php

namespace Tests\Unit;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\MariaDbDdlLock;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Native behavior on a task-owned MariaDB only; never connects to an ERP schema. */
class MariaDbDdlBoundaryTest extends TestCase
{
    private PDO $administrator;

    private ?PDO $guard = null;

    private string $schema;

    private string $account;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('127.0.0.1', getenv('DB_HOST'));
        self::assertSame('33316', getenv('DB_PORT'));
        self::assertStringStartsWith('codex_public_ids_test_', getenv('DB_DATABASE'));
        $this->administrator = $this->connect();
        self::assertStringStartsWith('10.4.32-MariaDB', $this->administrator->query('SELECT VERSION()')->fetchColumn());
        $suffix = bin2hex(random_bytes(6));
        $this->schema = 'codex_public_ids_test_ddl_'.$suffix;
        $this->account = 'fh_ddl_'.$suffix;
        $this->administrator->exec('CREATE DATABASE '.$this->schema);
        $this->administrator->exec('CREATE DATABASE '.$this->schema.'_sibling');
        $this->administrator->exec('USE '.$this->schema);
        $this->administrator->exec('CREATE TABLE probe (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
        $this->administrator->exec('INSERT INTO probe VALUES (1,10),(2,20)');
        $this->administrator->exec('CREATE TRIGGER original_trigger BEFORE INSERT ON probe FOR EACH ROW SET NEW.value=NEW.value');
        $this->administrator->exec('CREATE VIEW original_view AS SELECT id FROM probe');
        $this->administrator->exec('CREATE PROCEDURE original_routine() SELECT 1');
        $this->administrator->exec('CREATE FUNCTION original_function() RETURNS INT DETERMINISTIC RETURN 1');
        $this->administrator->exec('CREATE EVENT original_event ON SCHEDULE EVERY 1 DAY DISABLE DO SELECT 1');
        $this->administrator->exec("CREATE USER '".$this->account."'@'127.0.0.1' IDENTIFIED BY 'disposable-fixture-only'");
        $this->administrator->exec("GRANT RELOAD ON *.* TO '".$this->account."'@'127.0.0.1'");
        $this->guard = $this->connect($this->account, 'disposable-fixture-only');
    }

    protected function tearDown(): void
    {
        $this->guard = null;
        if (isset($this->administrator)) {
            if (isset($this->schema)) {
                self::assertStringStartsWith('codex_public_ids_test_ddl_', $this->schema);
                $this->administrator->exec('DROP DATABASE IF EXISTS '.$this->schema);
                $this->administrator->exec('DROP DATABASE IF EXISTS '.$this->schema.'_sibling');
                $this->administrator->exec('DROP DATABASE IF EXISTS '.$this->schema.'_another');
            }
            if (isset($this->account)) {
                self::assertStringStartsWith('fh_ddl_', $this->account);
                $this->administrator->exec("DROP USER IF EXISTS '".$this->account."'@'127.0.0.1'");
            }
        }
        parent::tearDown();
    }

    public function test_native_stage_blocks_privileged_table_and_object_changes(): void
    {
        $this->hold();
        $statements = [
            'alter_table' => 'ALTER TABLE probe ADD COLUMN extra INT',
            'create_table' => 'CREATE TABLE another (id INT) ENGINE=InnoDB',
            'drop_table' => 'DROP TABLE probe',
            'truncate_table' => 'TRUNCATE TABLE probe',
            'rename_table' => 'RENAME TABLE probe TO renamed_probe',
            'create_index' => 'CREATE INDEX probe_value ON probe(value)',
            'create_trigger' => 'CREATE TRIGGER additional_trigger BEFORE UPDATE ON probe FOR EACH ROW SET NEW.value=NEW.value',
            'drop_trigger' => 'DROP TRIGGER original_trigger',
            'create_view' => 'CREATE VIEW additional_view AS SELECT id FROM probe',
            'drop_view' => 'DROP VIEW original_view',
            'create_routine' => 'CREATE PROCEDURE additional_routine() SELECT 1',
            'alter_routine' => "ALTER PROCEDURE original_routine COMMENT 'changed'",
            'drop_routine' => 'DROP PROCEDURE original_routine',
            'create_event' => 'CREATE EVENT additional_event ON SCHEDULE EVERY 1 DAY DISABLE DO SELECT 1',
            'alter_event' => "ALTER EVENT original_event COMMENT 'changed'",
            'drop_event' => 'DROP EVENT original_event',
            'alter_database' => 'ALTER DATABASE '.$this->schema.' CHARACTER SET latin1',
            'create_database' => 'CREATE DATABASE '.$this->schema.'_another',
            'drop_database' => 'DROP DATABASE '.$this->schema.'_sibling',
            'create_function' => 'CREATE FUNCTION additional_function() RETURNS INT DETERMINISTIC RETURN 2',
            'alter_function' => "ALTER FUNCTION original_function COMMENT 'changed'",
            'drop_function' => 'DROP FUNCTION original_function',
        ];
        $results = [];
        foreach ($statements as $name => $sql) {
            try {
                $this->administrator->exec($sql);
                $results[$name] = 'allowed';
            } catch (PDOException $failure) {
                self::assertSame(1205, $failure->errorInfo[1], $name.': '.$failure->getMessage());
                $results[$name] = 'blocked';
            }
        }
        file_put_contents(dirname(__DIR__, 2).'/storage/framework/testing/ddl-boundary-native-matrix.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::assertSame(array_fill_keys(array_keys($statements), 'blocked'), $results);
    }

    public function test_innodb_transactions_commit_and_consistent_reads_remain_stable(): void
    {
        $this->hold();
        $reader = $this->connect();
        $reader->exec('USE '.$this->schema);
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $reader->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        self::assertSame(30, (int) $reader->query('SELECT SUM(value) FROM probe')->fetchColumn());
        $this->administrator->beginTransaction();
        $this->administrator->exec('UPDATE probe SET value=value+10');
        $this->administrator->exec('INSERT INTO probe VALUES (3,30)');
        $this->administrator->commit();
        self::assertSame(30, (int) $reader->query('SELECT SUM(value) FROM probe')->fetchColumn());
        $reader->commit();
        self::assertSame(80, (int) $this->administrator->query('SELECT SUM(value) FROM probe')->fetchColumn());
    }

    public function test_other_connection_cannot_release_owner_stage_and_disconnect_releases_it(): void
    {
        $this->hold();
        try {
            $this->administrator->exec('BACKUP STAGE END');
            self::fail('Another connection released the stage.');
        } catch (PDOException $failure) {
            self::assertNotSame(1205, $failure->errorInfo[1]);
        }
        try {
            $this->administrator->exec('ALTER TABLE probe ADD COLUMN before_disconnect INT');
            self::fail('The owner stage was lost.');
        } catch (PDOException $failure) {
            self::assertSame(1205, $failure->errorInfo[1]);
        }
        $this->guard = null;
        $this->administrator->exec('ALTER TABLE probe ADD COLUMN after_disconnect INT');
        self::assertSame('after_disconnect', $this->administrator->query("SHOW COLUMNS FROM probe LIKE 'after_disconnect'")->fetchColumn());
    }

    public function test_killed_owner_cannot_silently_reconnect_and_ddl_is_released(): void
    {
        $this->hold();
        $owner = (int) $this->guard->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->administrator->exec('KILL CONNECTION '.$owner);
        try {
            $this->guard->query('SELECT CONNECTION_ID()');
            self::fail('Dead owner connection was accepted.');
        } catch (PDOException $failure) {
            self::assertContains($failure->errorInfo[1], [2006, 2013]);
        }
        $this->administrator->exec('ALTER TABLE probe ADD COLUMN after_kill INT');
        self::assertSame('after_kill', $this->administrator->query("SHOW COLUMNS FROM probe LIKE 'after_kill'")->fetchColumn());
    }

    public function test_wrapper_releases_after_failure_and_can_retry_without_reusing_a_session(): void
    {
        $identity = $this->guard->query('SELECT VERSION() AS version, @@port AS port, CONNECTION_ID() AS owner')->fetch(PDO::FETCH_ASSOC);
        self::assertStringStartsWith('10.4.32-MariaDB', $identity['version']);
        self::assertSame(33316, (int) $identity['port']);
        $lock = $this->wrapper();
        try {
            $lock->during(function () use ($lock): void {
                $lock->assertHeld();
                try {
                    $this->administrator->exec('ALTER TABLE probe ADD COLUMN unexpected INT');
                    self::fail('DDL was allowed during the wrapper scope.');
                } catch (PDOException $failure) {
                    self::assertSame(1205, $failure->errorInfo[1]);
                }
                try {
                    $lock->during(static fn (): int => 99);
                    self::fail('Nested acquisition was accepted.');
                } catch (BackupFailure $failure) {
                    self::assertSame('capture_protection_unavailable', $failure->getMessage());
                }
                $lock->assertHeld();
                throw new BackupFailure('files_changed');
            });
            self::fail('Interrupted capture returned a result.');
        } catch (BackupFailure $failure) {
            self::assertSame('files_changed', $failure->getMessage());
        }
        $this->administrator->exec('ALTER TABLE probe ADD COLUMN after_failure INT');
        self::assertSame(42, $lock->during(static fn (): int => 42));
        $this->expectException(BackupFailure::class);
        $lock->assertHeld();
    }

    public function test_wrapper_rejects_killed_owner_instead_of_publishing_or_reconnecting(): void
    {
        $this->guard = null;
        $lock = $this->wrapper();
        try {
            $lock->during(function (): string {
                $statement = $this->administrator->prepare('SELECT ID FROM information_schema.PROCESSLIST WHERE USER=?');
                $statement->execute([$this->account]);
                $owners = $statement->fetchAll(PDO::FETCH_COLUMN);
                self::assertCount(1, $owners);
                $this->administrator->exec('KILL CONNECTION '.(int) $owners[0]);

                return 'must-not-be-published';
            });
            self::fail('A result escaped after the owner connection was killed.');
        } catch (BackupFailure $failure) {
            self::assertSame('capture_protection_unavailable', $failure->getMessage());
        }
        $this->administrator->exec('ALTER TABLE probe ADD COLUMN after_owner_loss INT');
        self::assertSame(43, $lock->during(static fn (): int => 43));
    }

    public function test_wrapper_wait_is_bounded_and_does_not_end_another_owners_stage(): void
    {
        $this->hold();
        $entered = false;
        try {
            $this->wrapper()->during(function () use (&$entered): void {
                $entered = true;
            });
            self::fail('A competing backup stage was accepted.');
        } catch (BackupFailure $failure) {
            self::assertSame('capture_protection_unavailable', $failure->getMessage());
        }
        self::assertFalse($entered);
        try {
            $this->administrator->exec('ALTER TABLE probe ADD COLUMN before_release INT');
            self::fail('Acquisition failure released another owner.');
        } catch (PDOException $failure) {
            self::assertSame(1205, $failure->errorInfo[1]);
        }
    }

    public function test_wrapper_aborts_after_native_idle_timeout_and_releases_ddl(): void
    {
        try {
            $this->wrapper()->during(function (): string {
                usleep(16500000);
                $this->administrator->exec('ALTER TABLE probe ADD COLUMN after_idle_expiry INT');

                return 'must-not-be-published';
            });
            self::fail('Expired protection returned a publishable result.');
        } catch (BackupFailure $failure) {
            self::assertSame('capture_protection_unavailable', $failure->getMessage());
        }
        self::assertSame('after_idle_expiry', $this->administrator->query("SHOW COLUMNS FROM probe LIKE 'after_idle_expiry'")->fetchColumn());
    }

    public function test_partial_stage_acquisition_failure_is_cleaned_before_retry(): void
    {
        $this->administrator->exec('CREATE TABLE nontransactional_probe (id INT) ENGINE=Aria');
        $this->administrator->exec('LOCK TABLES nontransactional_probe WRITE');
        try {
            $this->wrapper()->during(static fn (): int => 1);
            self::fail('A held nontransactional writer did not prevent acquisition.');
        } catch (BackupFailure $failure) {
            self::assertSame('capture_protection_unavailable', $failure->getMessage());
        } finally {
            $this->administrator->exec('UNLOCK TABLES');
        }
        self::assertSame(44, $this->wrapper()->during(static fn (): int => 44));
    }

    public function test_least_privilege_native_dump_remains_consistent_during_concurrent_commits(): void
    {
        $reader = $this->account.'_r';
        $this->administrator->exec("CREATE USER '".$reader."'@'127.0.0.1' IDENTIFIED BY ''");
        try {
            $this->administrator->exec('GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON '.$this->schema.".* TO '".$reader."'@'127.0.0.1'");
            $this->administrator->exec("GRANT SELECT ON mysql.proc TO '".$reader."'@'127.0.0.1'");
            $readerConnection = $this->connect($reader, '');
            $readerConnection->exec('USE '.$this->schema);
            try {
                $readerConnection->exec('ALTER TABLE probe ADD COLUMN denied INT');
                self::fail('The dump reader has DDL privileges.');
            } catch (PDOException $failure) {
                self::assertSame(1142, $failure->errorInfo[1]);
            }
            for ($id = 3; $id <= 82; $id++) {
                $this->administrator->exec('INSERT INTO probe VALUES ('.$id.',1)');
            }
            $this->wrapper()->during(function () use ($reader): void {
                $process = new Process(['C:/xampp/mysql/bin/mysqldump.exe', '--no-defaults', '--host=127.0.0.1', '--port=33316',
                    '--protocol=tcp', '--user='.$reader, '--password=', '--single-transaction', '--quick', '--skip-lock-tables',
                    '--routines', '--events', '--triggers', '--hex-blob', '--dump-date', '--where=SLEEP(0.02)=0', $this->schema]);
                $process->setTimeout(15)->start();
                try {
                    $observed = false;
                    $deadline = microtime(true) + 8;
                    $query = $this->administrator->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER=? AND DB=? AND INFO LIKE 'SELECT%FROM %probe%SLEEP%'");
                    do {
                        $query->execute([$reader, $this->schema]);
                        if ((int) $query->fetchColumn() > 0) {
                            $observed = true;
                            break;
                        }
                        usleep(10000);
                    } while ($process->isRunning() && microtime(true) < $deadline);
                    self::assertTrue($observed, 'No active native snapshot SELECT was observed.');
                    $this->administrator->beginTransaction();
                    $this->administrator->exec('UPDATE probe SET value=1000 WHERE id IN (1,2)');
                    $this->administrator->exec('INSERT INTO probe VALUES (10000,1000)');
                    $this->administrator->commit();
                    $process->wait();
                    self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                    $sql = $process->getOutput();
                    self::assertStringContainsString('(1,10),(2,20)', $sql);
                    self::assertStringNotContainsString('(10000,1000)', $sql);
                    foreach (['original_trigger', 'original_routine', 'original_function', 'original_event', '-- Dump completed on'] as $evidence) {
                        self::assertStringContainsString($evidence, $sql);
                    }
                    self::assertSame(1000, (int) $this->administrator->query('SELECT value FROM probe WHERE id=1')->fetchColumn());
                } finally {
                    if ($process->isRunning()) {
                        $process->stop(1);
                    }
                }
            });
        } finally {
            $this->administrator->exec("DROP USER IF EXISTS '".$reader."'@'127.0.0.1'");
        }
    }

    public function test_waiting_ddl_does_not_block_ordinary_innodb_commits(): void
    {
        $this->hold();
        $process = $this->alterProcess('after_pending_ddl');
        $process->start();
        try {
            $this->awaitDdlWait($process, '%backup%');
            $this->administrator->beginTransaction();
            $this->administrator->exec('UPDATE probe SET value=value+10');
            $this->administrator->exec('INSERT INTO probe VALUES (3,30)');
            $this->administrator->commit();
            self::assertSame(80, (int) $this->administrator->query('SELECT SUM(value) FROM probe')->fetchColumn());
            $this->guard = null;
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        } finally {
            if ($this->administrator->inTransaction()) {
                $this->administrator->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    public function test_preexisting_ddl_remains_excluded_when_its_table_lock_is_released(): void
    {
        $this->administrator->beginTransaction();
        $this->administrator->query('SELECT * FROM probe')->fetchAll();
        $process = $this->alterProcess('after_existing_ddl');
        $process->start();
        try {
            $this->awaitDdlWait($process, '%metadata lock%');
            $lock = $this->wrapper();
            $lock->during(function () use ($process, $lock): void {
                $this->administrator->rollBack();
                $this->awaitDdlWait($process, '%backup%');
                $lock->assertHeld();
                self::assertTrue($process->isRunning());
            });
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('after_existing_ddl', $this->administrator->query("SHOW COLUMNS FROM probe LIKE 'after_existing_ddl'")->fetchColumn());
            self::assertSame(45, $this->wrapper()->during(static fn (): int => 45));
        } finally {
            if ($this->administrator->inTransaction()) {
                $this->administrator->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    private function alterProcess(string $column): Process
    {
        self::assertMatchesRegularExpression('/\A[a-z_]+\z/D', $column);

        return (new Process(['C:/xampp/mysql/bin/mysql.exe', '--no-defaults', '--host=127.0.0.1', '--port=33316', '--protocol=tcp',
            '--user=root', '--password=', '--database='.$this->schema, '--execute=SET SESSION lock_wait_timeout=10; ALTER TABLE probe ADD COLUMN '.$column.' INT']))->setTimeout(15);
    }

    private function awaitDdlWait(Process $process, string $state): void
    {
        $deadline = microtime(true) + 5;
        $query = $this->administrator->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=? AND COMMAND='Query' AND ID<>CONNECTION_ID() AND STATE LIKE ?");
        do {
            $query->execute([$this->schema, $state]);
            if ((int) $query->fetchColumn() > 0) {
                self::assertTrue($process->isRunning());

                return;
            }
            usleep(50000);
        } while ($process->isRunning() && microtime(true) < $deadline);
        self::fail('The expected native DDL wait was not observed.');
    }

    private function wrapper(): MariaDbDdlLock
    {
        return new MariaDbDdlLock('127.0.0.1', 33316, $this->account, 'disposable-fixture-only');
    }

    private function hold(): void
    {
        $this->guard->exec('BACKUP STAGE START');
        $this->guard->exec('BACKUP STAGE BLOCK_DDL');
    }

    private function connect(string $user = 'root', string $password = ''): PDO
    {
        $connection = new PDO('mysql:host=127.0.0.1;port=33316;charset=utf8mb4', $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 3,
        ]);
        $connection->exec('SET SESSION lock_wait_timeout=1');
        $connection->exec('SET SESSION max_statement_time=3');

        return $connection;
    }
}
