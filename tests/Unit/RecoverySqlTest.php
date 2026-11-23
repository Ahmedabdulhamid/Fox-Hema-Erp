<?php

namespace Tests\Unit;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\RecoverySql;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BackupFileEnvironment;
use Tests\TestCase;

class RecoverySqlTest extends TestCase
{
    use BackupFileEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupFiles();
    }

    protected function tearDown(): void
    {
        $this->removeBackupTestDirectory($this->backupTestRoot);
        parent::tearDown();
    }

    public function test_literal_rows_and_dump_wrappers_are_supported_without_executing_comment_settings(): void
    {
        $sql = "-- test dump\n/*!40101 SET @old=@@sql_mode */;\n".$this->table()."\n/*!40000 ALTER TABLE `items` DISABLE KEYS */;\nINSERT INTO ".chr(96).'items'.chr(96)." VALUES (1,'text; DROP DATABASE x'),(-2,'second');\n/*!40000 ALTER TABLE `items` ENABLE KEYS */;";
        $source = $this->backupTestRoot.'/source.sql';
        file_put_contents($source, $sql);
        $output = $this->backupTestRoot.'/safe.sql';
        $counts = app(RecoverySql::class)->prepare($source, $output, static fn () => null);
        $this->assertSame(['items' => 2], $counts);
        $this->assertStringNotContainsString('@old', file_get_contents($output));
        $this->assertStringNotContainsString('DISABLE KEYS', file_get_contents($output));
        $this->assertStringNotContainsString('ENABLE KEYS', file_get_contents($output));
    }

    #[DataProvider('unsafeSql')]
    public function test_unsupported_sql_is_rejected_before_any_import(string $unsafe): void
    {
        $source = $this->backupTestRoot.'/source.sql';
        file_put_contents($source, $this->table().$unsafe);
        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('restore_sql_unsupported');
        app(RecoverySql::class)->prepare($source, $this->backupTestRoot.'/safe.sql', static fn () => null);
    }

    public static function unsafeSql(): array
    {
        return array_map(static fn ($sql) => [$sql], [
            'USE fox_hema_pos;', 'DROP DATABASE fox_hema_pos;', 'CREATE DATABASE other;',
            "/*!40103 SET TIME_ZONE='+12:00' */;",
            'INSERT INTO other.items VALUES (1);', 'INSERT INTO items SELECT 1;',
            'LOAD DATA LOCAL INFILE \'secret\' INTO TABLE items;', 'SELECT 1 INTO OUTFILE \'x\';',
            'CREATE TRIGGER t AFTER INSERT ON items FOR EACH ROW SET @x=1;',
            'CREATE DEFINER=root PROCEDURE p() SELECT 1;', 'CREATE EVENT e ON SCHEDULE EVERY 1 DAY DO SELECT 1;',
            '/*!50003 CREATE TRIGGER t AFTER INSERT ON items FOR EACH ROW SET @x=1 */;',
            '/*!40000 ALTER TABLE `items` DROP COLUMN `name` */;',
            '/*!40000 ALTER TABLE `other`.`items` DISABLE KEYS */;',
            '/*!40000 ALTER TABLE `items` DISABLE KEYS; DROP DATABASE other */;',
            'INSERT INTO '.chr(96).'items'.chr(96).' VALUES (SLEEP(10));',
            'INSERT INTO '.chr(96).'items'.chr(96).' VALUES ((SELECT 1));',
            'INSERT INTO `items` VALUES ();',
            'INSERT INTO `items` VALUES (1,);',
            'INSERT INTO `items` VALUES (0xGG);',
            'INSERT INTO `items` VALUES (_binaryNULL);',
            'INSERT INTO `items` VALUES (1e);',
            'CREATE TABLE '.chr(96).'escape'.chr(96).' ('.chr(96).'x'.chr(96).' int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DATA DIRECTORY=\'/tmp\';',
            'CREATE TABLE `escape` (`x` int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'safe\' DATA DIRECTORY=\'/tmp\';',
            '\\! echo unsafe', 'SET GLOBAL event_scheduler=ON;', 'GRANT ALL ON *.* TO root;',
            'CREATE TABLE '.chr(96).'unsafe'.chr(96).' ('.chr(96).'id'.chr(96).' int, CONSTRAINT '.chr(96).'check_bad'.chr(96).' CHECK (evil_function('.chr(96).'id'.chr(96).'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
            'CREATE TABLE '.chr(96).'bad'.chr(96).' ('.chr(96).'id'.chr(96).' int, CONSTRAINT '.chr(96).'fk'.chr(96).' FOREIGN KEY ('.chr(96).'id'.chr(96).') REFERENCES other.parent ('.chr(96).'id'.chr(96).')) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        ]);
    }

    #[DataProvider('generatedDefinitions')]
    public function test_generated_column_ddl_preserves_only_valid_same_table_expressions(string $definition, bool $valid): void
    {
        $sql = 'CREATE TABLE `generated_probe` (`id` bigint unsigned, `location_id` bigint unsigned, '.$definition.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
        $source = $this->backupTestRoot.'/generated.sql';
        $output = $this->backupTestRoot.'/generated-safe.sql';
        file_put_contents($source, $sql);
        if (! $valid) {
            $this->expectException(BackupFailure::class);
            $this->expectExceptionMessage('restore_sql_unsupported');
        }
        $counts = app(RecoverySql::class)->prepare($source, $output, static fn () => null);
        if ($valid) {
            $this->assertSame(['generated_probe' => 0], $counts);
            $this->assertStringContainsString($definition, file_get_contents($output));
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function generatedDefinitions(): iterable
    {
        yield 'subtraction operator' => ['`computed` bigint GENERATED ALWAYS AS ((`id` - 2)) STORED', true];
        foreach (['STORED', 'PERSISTENT', 'VIRTUAL'] as $mode) {
            yield $mode => ['`computed` bigint unsigned GENERATED ALWAYS AS (case when `location_id` is null then `id` else NULL end) '.$mode, true];
        }
        foreach ([
            '`computed` int GENERATED ALWAYS AS (SLEEP(1)) STORED',
            '`computed` varchar(100) GENERATED ALWAYS AS (\'text\') VIRTUAL',
            '`computed` int GENERATED ALWAYS AS ((SELECT 1)) STORED',
            '`computed` int GENERATED ALWAYS AS (`other`.`id`) STORED',
            '`computed` int GENERATED ALWAYS AS (`computed`) STORED',
            '`computed` int GENERATED ALWAYS AS (`missing`) VIRTUAL',
            '`computed` int GENERATED ALWAYS AS (`id`) STORED INVISIBLE',
            '`computed` int GENERATED ALWAYS AS (`id`) STORED COMMENT=\'x\' DATA DIRECTORY=\'/tmp\'',
            '`computed` int GENERATED ALWAYS AS (`id`) STORED; DROP TABLE `generated_probe`',
            '`computed` int GENERATED ALWAYS AS (1 /*!50003 + SLEEP(1) */) VIRTUAL',
            '`computed` int GENERATED ALWAYS AS (`id`(`id`)) STORED',
            '`computed` int GENERATED ALWAYS AS (`peer`) STORED, `peer` int GENERATED ALWAYS AS (`id`) STORED',
        ] as $index => $definition) {
            yield 'unsafe '.$index => [$definition, false];
        }
    }

    private function table(): string
    {
        $q = chr(96);

        return 'CREATE TABLE '.$q.'items'.$q.' ('.$q.'id'.$q.' int NOT NULL, '.$q.'name'.$q.' varchar(100) DEFAULT NULL, PRIMARY KEY ('.$q.'id'.$q.')) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'Synthetic table comment\';';
    }

    #[DataProvider('incompleteDumps')]
    public function test_truncated_or_corrupted_statements_are_rejected(string $dump): void
    {
        $source = $this->backupTestRoot.'/incomplete.sql';
        file_put_contents($source, $dump);
        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('restore_sql_unsupported');
        app(RecoverySql::class)->prepare($source, $this->backupTestRoot.'/incomplete-safe.sql', static fn () => null);
    }

    public function test_extended_native_insert_with_many_literal_rows_is_supported(): void
    {
        $source = $this->backupTestRoot.'/extended.sql';
        $rows = array_map(static fn (int $id): string => "({$id},'2026-11-14 12:34:56')", range(1, 1000));
        file_put_contents($source, $this->table().'INSERT INTO `items` VALUES '.implode(',', $rows).';');
        $this->assertSame(['items' => 1000], app(RecoverySql::class)->prepare($source, $this->backupTestRoot.'/extended-safe.sql', static fn () => null));
    }

    public function test_native_binary_literals_and_large_escaped_strings_remain_literal_data(): void
    {
        $source = $this->backupTestRoot.'/binary-literals.sql';
        $output = $this->backupTestRoot.'/binary-safe.sql';
        $values = "(1,0x4142),(2,_binary'AB'),(3,'quoted''value'),(4,'escaped".chr(92)."'value'),(5,'".str_repeat('ab', 32768)."')";
        file_put_contents($source, $this->table().'INSERT INTO `items` VALUES '.$values.';');
        $this->assertSame(['items' => 5], app(RecoverySql::class)->prepare($source, $output, static fn () => null));
        $this->assertStringContainsString($values, file_get_contents($output));
    }

    public function test_native_inventory_check_subtraction_is_preserved(): void
    {
        $source = $this->backupTestRoot.'/inventory-check.sql';
        $output = $this->backupTestRoot.'/inventory-check-safe.sql';
        $definition = 'CREATE TABLE `balances` (`available` decimal(18,4), `on_hand` decimal(18,4), `reserved` decimal(18,4), CONSTRAINT `arithmetic` CHECK (`available` = `on_hand` - `reserved`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
        file_put_contents($source, $definition);
        $this->assertSame(['balances' => 0], app(RecoverySql::class)->prepare($source, $output, static fn () => null));
        $this->assertStringContainsString($definition, file_get_contents($output));
    }

    /** @return list<array{string}> */
    public static function incompleteDumps(): array
    {
        return [
            ['CREATE TABLE `items` (`id` int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],
            ['CREATE TABLE `items` (`id` int, `computed` int GENERATED ALWAYS AS (CASE WHEN `id` IS NULL THEN'],
            ["CREATE TABLE `items` (`id` int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; INSERT INTO `items` VALUES ('unfinished"],
            ['CREATE TABLE `items` (`id` int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; INSERT INTO `items` VALUES (1,);'],
            ["CREATE TABLE `items` (`id` int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\0"],
        ];
    }
}
