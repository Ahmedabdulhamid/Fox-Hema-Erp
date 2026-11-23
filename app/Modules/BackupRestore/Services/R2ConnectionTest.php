<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\BackupRestore\Models\BackupDestination;
use Illuminate\Support\Facades\DB;
use Throwable;

class R2ConnectionTest
{
    public function check(User $actor): bool
    {
        app(BackupAccess::class)->authorize($actor, BackupPermissions::SETTINGS);
        $destination = BackupDestination::query()->where('status', 'active')->latest('id')->first();
        abort_unless($destination, 422, __('backup_restore.not_configured'));
        try {
            app(R2Storage::class)->probe($destination);
            $passed = true;
        } catch (Throwable) {
            // SDK exception messages and chains can contain signed requests and credentials.
            $passed = false;
        }
        DB::transaction(function () use ($destination, $actor, $passed): void {
            $updated = BackupDestination::query()->whereKey($destination->id)
                ->where('status', 'active')->where('configuration_version', $destination->configuration_version)
                ->update(['last_checked_at' => now(), 'probe_status' => $passed ? 'passed' : 'failed']);
            if ($updated) {
                app(AuditLogger::class)->record('backups.connection_checked', metadata: [
                    'destination_id' => $destination->id, 'configuration_version' => $destination->configuration_version, 'passed' => $passed,
                ], actor: $actor);
            }
        });
        app(BackupReadiness::class)->forgetCachedBlockers();

        return $passed;
    }
}
