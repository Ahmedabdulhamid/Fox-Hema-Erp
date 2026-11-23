<?php

namespace App\Console\Commands;

use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Services\LocalOfflineSaleRecovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('pos:recover-local-test-sale {evidence : Full browser JSON export path} {--reviewer=1} {--reason= : Documented local-test disposition reason} {--archive-orphan : Archive only the allowlisted historical orphan A} {--apply : Apply only the allowlisted terminal disposition}')]
#[Description('Verify the authorized local cancellation or historical archival; never seals an outbox or closes a shift')]
class RecoverLocalOfflineSale extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LocalOfflineSaleRecovery $recovery): int
    {
        try {
            if (! $recovery->enabled()) {
                $this->error('Refusing recovery outside the specified local database.');

                return self::FAILURE;
            }
            $raw = file_get_contents($this->argument('evidence'));
            $evidence = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $scope = $evidence['scope'] ?? [];
            setPermissionsTeamId($scope['branch_id'] ?? 0);
            $reviewer = User::findOrFail($this->option('reviewer'));
            $transaction = $this->option('archive-orphan')
                ? $recovery->archive($reviewer, $evidence, (string) $this->option('reason'), (bool) $this->option('apply'), $raw)
                : $recovery->cancel($reviewer, $evidence, (string) $this->option('reason'), (bool) $this->option('apply'), $raw);
            $this->info($transaction === null ? 'Verified; no changes. Use --apply to record the authorized disposition.'
                : 'Terminal disposition recorded idempotently. Original evidence retained. Outbox and shift remain unsealed/open.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
