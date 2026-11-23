<?php

namespace App\Console\Commands;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LoadTestingAccessBootstrapper;
use App\Modules\AccessManagement\Services\LoadTestingCredentialStore;
use App\Modules\AccessManagement\Services\LoadTestingEnvironmentGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('load-testing:bootstrap-access')]
#[Description('Provision the isolated load-testing branch, role, and Filament user')]
class LoadTestingBootstrapAccess extends Command
{
    public function handle(
        LoadTestingEnvironmentGuard $guard,
        LoadTestingCredentialStore $credentials,
        LoadTestingAccessBootstrapper $bootstrapper,
    ): int {
        try {
            $guard->assertSafe();
            $administratorExists = User::withTrashed()
                ->where('email', LoadTestingAccessBootstrapper::USER_EMAIL)
                ->exists();
            $administratorCredential = $administratorExists
                ? $credentials->loadExisting()
                : $credentials->loadOrCreate();
            $cashierAccountsExist = User::withTrashed()
                ->whereIn('email', LoadTestingAccessBootstrapper::cashierEmails())
                ->exists();
            $cashierCredentials = $cashierAccountsExist
                ? $credentials->loadExistingCashierCredentials()
                : $credentials->loadOrCreateCashierCredentials();
            $result = $bootstrapper->bootstrap($administratorCredential['password'], $cashierCredentials);
            unset($administratorCredential, $cashierCredentials);
        } catch (Throwable $exception) {
            $this->error('Load-testing access bootstrap refused: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Isolated load-testing access is ready.');
        $this->line('User: '.LoadTestingAccessBootstrapper::USER_EMAIL);
        $this->line('Branch ID: '.$result['location_id'].'; role ID: '.$result['role_id'].'; user ID: '.$result['user_id']);
        foreach (LoadTestingAccessBootstrapper::BRANCHES as $index => $branch) {
            $this->line('Cashier: '.$branch['cashier_email'].'; branch ID: '.$result['branch_ids'][$index]
                .'; role ID: '.$result['cashier_role_ids'][$index].'; user ID: '.$result['cashier_ids'][$index]);
        }
        $this->line('Credential file: '.$credentials->path());
        $this->line('Cashier credential file: '.$credentials->cashierPath());
        $this->line('The password was not displayed or logged.');

        return self::SUCCESS;
    }
}
