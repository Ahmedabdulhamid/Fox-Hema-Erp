<?php

namespace App\Modules\BackupRestore\Services;

use Closure;
use PDO;
use Symfony\Component\Process\Process;
use Throwable;

/** Privileged provisioning is separated from a fresh, target-only SQL importer account. */
class RecoveryDatabase
{
    /** @param array<string, mixed> $settings */
    public function administrator(#[\SensitiveParameter] array $settings): PDO
    {
        if (($settings['host'] ?? null) !== '127.0.0.1' || ! is_int($settings['port'] ?? null) || $settings['port'] < 1 || $settings['port'] > 65535
            || ! is_string($settings['username'] ?? null) || ! is_string($settings['password'] ?? null)) {
            throw new BackupFailure('restore_target_unsafe');
        }
        $pdo = new PDO('mysql:host=127.0.0.1;port='.$settings['port'].';charset=utf8mb4', $settings['username'], $settings['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_EMULATE_PREPARES => false]);
        $actual = $pdo->query('SELECT @@port AS port, VERSION() AS version, @@general_log AS general_log, @@slow_query_log AS slow_log, @@log_bin AS binary_log')->fetch(PDO::FETCH_ASSOC);
        if ((int) $actual['port'] !== $settings['port'] || ! str_contains($actual['version'], 'MariaDB')
            || (int) $actual['general_log'] !== 0 || (int) $actual['slow_log'] !== 0 || (int) $actual['binary_log'] !== 0) {
            throw new BackupFailure('restore_target_unsafe');
        }
        $privileges = [];
        $grantOption = false;
        foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (preg_match('/\AGRANT (.+) ON \*\.\* TO /i', $grant, $match)) {
                $privileges = array_merge($privileges, array_map('trim', explode(',', strtoupper($match[1]))));
                $grantOption = $grantOption || str_contains(strtoupper($grant), 'WITH GRANT OPTION');
            }
        }
        if (! $grantOption || (! in_array('ALL PRIVILEGES', $privileges, true)
            && (array_diff(['CREATE', 'DROP', 'CREATE USER', 'PROCESS', 'SELECT', 'INSERT', 'INDEX', 'REFERENCES'], $privileges) !== []
                || array_intersect(['SUPER', 'CONNECTION ADMIN'], $privileges) === []))) {
            throw new BackupFailure('restore_target_unsafe');
        }

        $pdo->exec("SET SESSION time_zone='+00:00'");

        return $pdo;
    }

    public function assertTarget(string $target, RecoveryWorkspace $workspace): void
    {
        $workspace->assertOwned();
        if ($target !== 'fh_recovery_'.$workspace->operation || ! preg_match('/\Afh_recovery_[a-f0-9]{32}\z/D', $target)) {
            throw new BackupFailure('restore_target_unsafe');
        }
    }

    /** @param array<string, mixed> $settings */
    public function import(#[\SensitiveParameter] array $settings, string $target, RecoveryWorkspace $workspace, #[\SensitiveParameter] string $owner, Closure $tick): PDO
    {
        $this->assertTarget($target, $workspace);
        $admin = $this->administrator($settings);
        $username = 'fhr_'.substr(hash('sha256', $owner), 0, 24);
        $account = $admin->quote($username)."@'127.0.0.1'";
        $password = bin2hex(random_bytes(32));
        $createdUser = false;
        $input = null;
        try {
            $binary = config('backup-restore.restore.client_binary');
            if (! is_string($binary) || ! is_file($binary) || is_link($binary) || ! preg_match('~\A(?:[a-zA-Z]:[\\\\/]|/)~', $binary)) {
                throw new BackupFailure('restore_disabled');
            }
            $version = new Process([$binary, '--no-defaults', '--version']);
            $version->setTimeout(5)->run();
            $serverVersion = (string) $admin->query('SELECT VERSION()')->fetchColumn();
            if (! $version->isSuccessful() || ! str_contains($version->getOutput(), 'MariaDB')
                || ! preg_match('/Distrib\s+(\d+\.\d+)/', $version->getOutput(), $clientVersion)
                || ! preg_match('/\A(\d+\.\d+)/', $serverVersion, $serverMatch) || $clientVersion[1] !== $serverMatch[1]) {
                throw new BackupFailure('dump_incompatible');
            }
            $existingUser = $admin->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=?');
            $existingUser->execute([$username]);
            if ((int) $existingUser->fetchColumn() !== 0) {
                throw new BackupFailure('restore_target_unsafe');
            }
            $admin->exec('CREATE DATABASE '.chr(96).$target.chr(96).' CHARACTER SET utf8mb4 COLLATE '.TrustedTreasuryTriggers::DATABASE_COLLATION);
            $admin->exec('CREATE TABLE '.chr(96).$target.chr(96).'.__fh_recovery_owner (token_hash char(64) NOT NULL PRIMARY KEY)');
            $marker = $admin->prepare('INSERT INTO '.chr(96).$target.chr(96).'.__fh_recovery_owner VALUES (?)');
            $marker->execute([hash('sha256', $owner)]);
            $workspace->record('database_created');
            $admin->exec('CREATE USER '.$account.' IDENTIFIED BY '.$admin->quote($password));
            $createdUser = true;
            $grantTarget = str_replace('_', '\\_', $target);
            $admin->exec('GRANT SELECT, INSERT, CREATE, INDEX, REFERENCES ON '.chr(96).$grantTarget.chr(96).'.* TO '.$account);
            $workspace->record('importing');
            $stream = app(PrivateFiles::class)->create($workspace->files->file('client.cnf'));
            PrivateFiles::write($stream, "[client]\nprotocol=tcp\nhost=127.0.0.1\nport=".$settings['port']."\nuser=".$username."\npassword=".$password."\n");
            fclose($stream);
            $input = fopen($workspace->files->file('import.sql'), 'rb');
            $process = new Process([$binary, '--defaults-file='.$workspace->files->file('client.cnf'), '--batch', '--binary-mode', '--skip-reconnect',
                '--local-infile=0', '--connect-timeout=3', '--database='.$target]);
            $process->setInput($input)->setTimeout((int) config('backup-restore.restore.timeout'))->disableOutput()->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $tick();
                usleep(100000);
            }
            if (! $process->isSuccessful()) {
                throw new BackupFailure('restore_failed');
            }
            $workspace->record('database_imported');
        } catch (Throwable $failure) {
            throw BackupFailure::sanitized($failure, 'restore_failed');
        } finally {
            if (isset($process) && $process->isRunning()) {
                $process->stop(1);
            }
            if (is_resource($input)) {
                fclose($input);
            }
            if ($createdUser) {
                try {
                    $connections = $admin->prepare('SELECT ID FROM information_schema.PROCESSLIST WHERE USER=?');
                    $connections->execute([$username]);
                    foreach ($connections->fetchAll(PDO::FETCH_COLUMN) as $connectionId) {
                        $admin->exec('KILL CONNECTION '.(int) $connectionId);
                    }
                    $admin->exec('DROP USER '.$account);
                } catch (Throwable) {
                    throw new BackupFailure('restore_cleanup_unverified');
                }
            }
            if (is_file($workspace->files->file('client.cnf'))) {
                $workspace->files->remove('client.cnf');
            }
            sodium_memzero($password);
        }
        $admin->exec('USE '.chr(96).$target.chr(96));
        $admin->exec('SET SESSION max_statement_time=5');
        $admin->exec('SET SESSION lock_wait_timeout=3');

        return $admin;
    }

    /** Only application-owned definitions reach this privileged provisioning stage.
     * @param  array<string, mixed>  $profile
     */
    public function installTriggers(PDO $admin, string $target, RecoveryWorkspace $workspace, #[\SensitiveParameter] string $owner, array $profile, Closure $tick): void
    {
        $this->assertTarget($target, $workspace);
        $catalog = app(TrustedTreasuryTriggers::class);
        $catalog->authenticate(['release_id' => $profile['release_id'] ?? '', 'database' => ['trigger_profile' => $profile]]);
        if ($admin->query('SELECT DATABASE()')->fetchColumn() !== $target
            || $admin->query('SELECT token_hash FROM __fh_recovery_owner')->fetchColumn() !== hash('sha256', $owner)
            || $catalog->inventory($admin) !== []) {
            throw new BackupFailure('restore_target_unsafe');
        }
        $username = $this->triggerDefiner($owner);
        $existing = $admin->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=?');
        $existing->execute([$username]);
        if ((int) $existing->fetchColumn() !== 0) {
            throw new BackupFailure('restore_target_unsafe');
        }
        $account = $admin->quote($username)."@'127.0.0.1'";
        $mode = $admin->query('SELECT @@session.sql_mode')->fetchColumn();
        $charset = $admin->query('SELECT @@session.character_set_client')->fetchColumn();
        $collation = $admin->query('SELECT @@session.collation_connection')->fetchColumn();
        try {
            $admin->exec('CREATE USER '.$account.' IDENTIFIED BY '.$admin->quote(bin2hex(random_bytes(32))).' ACCOUNT LOCK');
            $workspace->record('trusted_definer_created');
            foreach (['payment_methods', 'treasury_accounts', 'treasury_entries', 'treasury_operations'] as $table) {
                $admin->exec('GRANT SELECT, TRIGGER ON `'.$target.'`.`'.$table.'` TO '.$account);
            }
            $admin->exec('SET SESSION sql_mode='.$admin->quote($catalog->sqlMode()));
            $admin->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            $workspace->record('installing_trusted_triggers');
            foreach ($catalog->definitions() as $name => $definition) {
                $tick();
                $admin->exec('CREATE DEFINER='.$account.' TRIGGER `'.$name.'` BEFORE '.$definition['event'].' ON `'.$definition['table'].'` FOR EACH ROW '.$definition['body']);
            }
            $catalog->assertInventory($catalog->inventory($admin), $username.'@127.0.0.1');
            $this->verifyTriggerDefiner($admin, $target, $owner);
            $workspace->record('trusted_triggers_installed', ['count' => 8]);
        } catch (Throwable $failure) {
            $workspace->record('trigger_installation_quarantined');
            throw BackupFailure::sanitized($failure, 'restore_verification_failed');
        } finally {
            $admin->exec('SET SESSION sql_mode='.$admin->quote($mode));
            $admin->exec('SET SESSION character_set_client='.$admin->quote($charset));
            $admin->exec('SET SESSION collation_connection='.$admin->quote($collation));
        }
    }

    public function triggerDefiner(#[\SensitiveParameter] string $owner): string
    {
        return 'fht_'.substr(hash('sha256', $owner), 0, 24);
    }

    public function verifyTriggerDefiner(PDO $admin, string $target, #[\SensitiveParameter] string $owner): void
    {
        $username = $this->triggerDefiner($owner);
        $account = $admin->quote($username)."@'127.0.0.1'";
        $locked = $admin->prepare("SELECT JSON_VALUE(Priv, '$.account_locked') FROM mysql.global_priv WHERE User=? AND Host='127.0.0.1'");
        $locked->execute([$username]);
        if ((int) $locked->fetchColumn() !== 1) {
            throw new BackupFailure('restore_verification_failed');
        }
        $expected = ['payment_methods', 'treasury_accounts', 'treasury_entries', 'treasury_operations'];
        $seen = [];
        foreach ($admin->query('SHOW GRANTS FOR '.$account)->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (str_contains($grant, 'GRANT OPTION')) {
                throw new BackupFailure('restore_verification_failed');
            }
            if (preg_match('/\AGRANT USAGE ON \*\.\* TO /', $grant)) {
                continue;
            }
            if (! preg_match('/\AGRANT SELECT, TRIGGER ON `'.preg_quote($target, '/').'`\.`([a-z_]+)` TO /', $grant, $match)
                || ! in_array($match[1], $expected, true) || str_contains($grant, 'GRANT OPTION')) {
                throw new BackupFailure('restore_verification_failed');
            }
            $seen[] = $match[1];
        }
        sort($seen);
        if ($seen !== $expected) {
            throw new BackupFailure('restore_verification_failed');
        }
    }

    /** Never called automatically after a failure; both independent ownership proofs are required.
     * @param  array<string, mixed>  $settings
     */
    public function cleanup(#[\SensitiveParameter] array $settings, string $target, RecoveryWorkspace $workspace, #[\SensitiveParameter] string $owner): void
    {
        $this->assertTarget($target, $workspace);
        $admin = $this->administrator($settings);
        $lockPath = dirname($workspace->files->path).'/recovery.lock';
        app(PrivateFiles::class)->assertPrivate($lockPath);
        $lock = fopen($lockPath, 'r+b');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new BackupFailure('restore_busy');
        }
        try {
            $importer = $admin->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=?');
            $importer->execute(['fhr_'.substr(hash('sha256', $owner), 0, 24)]);
            if ((int) $importer->fetchColumn() !== 0) {
                throw new BackupFailure('restore_cleanup_unverified');
            }
            $marker = $admin->query('SELECT token_hash FROM '.chr(96).$target.chr(96).'.__fh_recovery_owner')->fetchColumn();
            if (! is_string($marker) || ! hash_equals(hash('sha256', $owner), $marker)) {
                throw new BackupFailure('restore_cleanup_unverified');
            }
            $admin->exec('DROP DATABASE '.chr(96).$target.chr(96));
            $admin->exec('DROP USER IF EXISTS '.$admin->quote($this->triggerDefiner($owner))."@'127.0.0.1'");
            $workspace->record('database_removed');
            $workspace->removeTransient();
        } catch (Throwable $failure) {
            throw BackupFailure::sanitized($failure, 'restore_cleanup_unverified');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
