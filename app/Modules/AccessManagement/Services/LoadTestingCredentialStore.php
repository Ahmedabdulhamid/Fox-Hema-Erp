<?php

namespace App\Modules\AccessManagement\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class LoadTestingCredentialStore
{
    /** @return array{email: string, password: string} */
    public function loadOrCreate(): array
    {
        $this->ensureDirectory();

        if (is_file($this->path())) {
            $this->restrict($this->path());

            return $this->read();
        }

        $credential = [
            'email' => LoadTestingAccessBootstrapper::USER_EMAIL,
            'password' => Str::password(40, symbols: true),
        ];
        $payload = json_encode([
            ...$credential,
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if (! $this->writeNewFile($this->path(), $payload)) {
            if (is_file($this->path())) {
                return $this->read();
            }

            throw new RuntimeException('Unable to create the isolated credential file.');
        }

        return $credential;
    }

    /** @return array{email: string, password: string} */
    public function loadExisting(): array
    {
        $this->ensureDirectory();
        if (! is_file($this->path())) {
            throw new RuntimeException('The existing load-testing administrator credential file is required; refusing to replace credentials.');
        }
        $this->restrict($this->path());

        return $this->read();
    }

    /** @return array<string, string> */
    public function loadOrCreateCashierCredentials(): array
    {
        $this->ensureDirectory();
        $path = $this->cashierPath();
        if (is_file($path)) {
            $this->restrict($path);

            return $this->readCashierCredentials($path);
        }

        $credentials = [];
        foreach (LoadTestingAccessBootstrapper::cashierEmails() as $email) {
            $credentials[$email] = Str::password(40, symbols: true);
        }
        $payload = json_encode([
            'credentials' => $credentials,
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if (! $this->writeNewFile($path, $payload)) {
            if (is_file($path)) {
                $this->restrict($path);

                return $this->readCashierCredentials($path);
            }

            throw new RuntimeException('Unable to create the isolated cashier credential file.');
        }

        return $credentials;
    }

    /** @return array<string, string> */
    public function loadExistingCashierCredentials(): array
    {
        $this->ensureDirectory();
        $path = $this->cashierPath();
        if (! is_file($path)) {
            throw new RuntimeException('The existing cashier credential file is required; refusing to replace credentials.');
        }
        $this->restrict($path);

        return $this->readCashierCredentials($path);
    }

    public function path(): string
    {
        return storage_path('load-testing-credentials/filament-user.json');
    }

    public function cashierPath(): string
    {
        return storage_path('load-testing-credentials/cashiers.json');
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the isolated credential directory.');
        }
        $this->restrict($directory, directory: true);
    }

    private function writeNewFile(string $path, string $payload): bool
    {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_EX) || fwrite($handle, $payload) !== strlen($payload) || ! fflush($handle)) {
                throw new RuntimeException('Unable to write the isolated credential file.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        }
        fclose($handle);

        try {
            $this->restrict($path);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return true;
    }

    /** @return array{email: string, password: string} */
    private function read(): array
    {
        $contents = file_get_contents($this->path());
        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read the isolated credential file.');
        }
        $credential = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($credential)
            || ($credential['email'] ?? null) !== LoadTestingAccessBootstrapper::USER_EMAIL
            || ! is_string($credential['password'] ?? null)
            || strlen($credential['password']) < 32) {
            throw new RuntimeException('The isolated credential file is invalid.');
        }

        return ['email' => $credential['email'], 'password' => $credential['password']];
    }

    /** @return array<string, string> */
    private function readCashierCredentials(string $path): array
    {
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read the isolated cashier credential file.');
        }
        $credential = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($credential)) {
            throw new RuntimeException('The isolated cashier credential file is invalid.');
        }
        $credentials = $credential['credentials'] ?? null;
        if (! is_array($credentials)
            || array_keys($credentials) !== LoadTestingAccessBootstrapper::cashierEmails()) {
            throw new RuntimeException('The isolated cashier credential file is invalid.');
        }
        foreach ($credentials as $password) {
            if (! is_string($password) || strlen($password) < 32) {
                throw new RuntimeException('The isolated cashier credential file is invalid.');
            }
        }
        if (count(array_unique(array_values($credentials))) !== count($credentials)) {
            throw new RuntimeException('The isolated cashier credential file is invalid.');
        }

        return $credentials;
    }

    private function restrict(string $path, bool $directory = false): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $mode = $directory ? 0700 : 0600;
            if (! chmod($path, $mode) || (fileperms($path) & 0777) !== $mode) {
                throw new RuntimeException('Unable to restrict isolated credential permissions.');
            }

            return;
        }

        $domain = getenv('USERDOMAIN');
        $username = getenv('USERNAME');
        if (! is_string($domain) || $domain === '' || ! is_string($username) || $username === '') {
            throw new RuntimeException('Unable to identify the current Windows account for credential ACLs.');
        }

        $grant = $domain.'\\'.$username.($directory ? ':(OI)(CI)(F)' : ':(F)');
        $process = new Process(['icacls.exe', $path, '/inheritance:r', '/grant:r', $grant]);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Unable to restrict isolated credential ACLs.');
        }
    }
}
