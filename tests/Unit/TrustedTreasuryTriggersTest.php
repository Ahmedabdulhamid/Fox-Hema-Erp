<?php

namespace Tests\Unit;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\TrustedTreasuryTriggers;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BackupFileEnvironment;
use Tests\TestCase;

class TrustedTreasuryTriggersTest extends TestCase
{
    use BackupFileEnvironment;

    #[DataProvider('mutations')]
    public function test_exact_native_inventory_and_release_are_required(string $mutation): void
    {
        $this->setUpBackupFiles();
        $catalog = app(TrustedTreasuryTriggers::class);
        config(['backup-restore.release_id' => $catalog->releaseId()]);
        $profile = $catalog->reviewedProfile('root@localhost');
        $blocks = [];
        foreach ($catalog->definitions() as $name => $definition) {
            $blocks[] = "DELIMITER ;;\n/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `{$name}` BEFORE {$definition['event']} ON `{$definition['table']}` FOR EACH ROW\n{$definition['body']} */;;\nDELIMITER ;\n";
        }
        $native = implode('', $blocks);
        $native = match ($mutation) {
            'modified_body' => str_replace('append-only', 'editable', $native),
            'malicious_body' => str_replace('SIGNAL SQLSTATE', 'DELETE FROM users; SIGNAL SQLSTATE', $native),
            'unknown_definer' => str_replace('`root`@`localhost`', '`unknown`@`localhost`', $native),
            'unexpected_name' => str_replace('treasury_entries_no_update', 'unexpected_trigger', $native),
            'wrong_table' => str_replace('ON `treasury_entries`', 'ON `users`', $native),
            'wrong_timing' => str_replace(' BEFORE ', ' AFTER ', $native),
            'duplicate' => $native.$blocks[0],
            'wrong_order' => implode('', array_reverse($blocks)),
            'missing' => implode('', array_slice($blocks, 1)),
            'routine' => $native."DELIMITER ;;\nCREATE PROCEDURE bad() SELECT 1;;\nDELIMITER ;\n",
            'injected_wrapper' => str_replace('/*!50003 CREATE*/', '/*!50003 SET @bad=1 */;; CREATE', $native),
            'unterminated' => substr($native, 0, -13),
            default => $native,
        };
        if ($mutation === 'wrong_release') {
            $profile['release_id'] = 'untrusted-release';
        } elseif ($mutation === 'wrong_inventory') {
            $profile['inventory_checksum'] = str_repeat('a', 64);
        }
        $source = $this->backupTestRoot.'/native.sql';
        $output = $this->backupTestRoot.'/checked.sql';
        file_put_contents($source, "CREATE TABLE `sentinel` (`id` int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n".$native);
        try {
            if ($mutation !== 'valid') {
                $this->expectException(BackupFailure::class);
            }
            $catalog->prepare($source, $output, $profile, static fn () => null);
            $this->assertStringNotContainsString('TRIGGER', file_get_contents($output));
            $this->assertStringContainsString('sentinel', file_get_contents($output));
            $this->assertCount(8, $catalog->definitions());
        } finally {
            $this->removeBackupTestDirectory($this->backupTestRoot);
        }
    }

    public function test_current_erp_artifact_cannot_omit_the_authenticated_inventory(): void
    {
        $this->expectException(BackupFailure::class);
        app(TrustedTreasuryTriggers::class)->authenticate(['database' => ['database' => 'fox_hema_pos']]);
    }

    public static function mutations(): array
    {
        return array_map(static fn (string $value): array => [$value], ['valid', 'modified_body', 'malicious_body', 'unknown_definer', 'unexpected_name', 'wrong_table', 'wrong_timing', 'duplicate', 'wrong_order', 'missing', 'routine', 'injected_wrapper', 'unterminated', 'wrong_release', 'wrong_inventory']);
    }
}
