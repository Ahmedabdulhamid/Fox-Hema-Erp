<?php

namespace App\Modules\AccessManagement\Console\Commands;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\SuperAdminProvisioner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ProvisionSuperAdmin extends Command
{
    protected $signature = 'access:provision-super-admin {email : Explicit target email} {--assign-existing : Assign the role to an existing active user} {--name= : Name for a new user}';

    protected $description = 'Explicitly provision a global web Super Admin without changing existing credentials';

    public function handle(SuperAdminProvisioner $provisioner): int
    {
        $email = (string) $this->argument('email');
        $name = $this->option('name');
        $password = null;
        if (! $this->option('assign-existing') && ! User::withTrashed()->where('email', $email)->exists()) {
            if (! $this->input->isInteractive()) {
                $this->error('New users require an interactive name and hidden password prompt.');

                return self::FAILURE;
            }
            $name ??= $this->ask('Name');
            $password = $this->secret('Password (at least 12 characters)');
            if ($password !== $this->secret('Confirm password')) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }
        try {
            $provisioner->provision($email, (bool) $this->option('assign-existing'), $name, $password);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }
        $this->info('Super Admin access provisioned for the specified user.');

        return self::SUCCESS;
    }
}
