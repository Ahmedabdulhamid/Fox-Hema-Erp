<?php

namespace App\Modules\BackupRestore\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\BackupRestore\Database\Factories\BackupRestoreRunFactory;
use App\Modules\BackupRestore\Services\BackupFailure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class BackupRestoreRun extends Model
{
    use HasFactory;

    protected static function newFactory(): BackupRestoreRunFactory
    {
        return BackupRestoreRunFactory::new();
    }

    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['verification_findings', 'artifact_identity', 'owner_token', 'request_key'];

    protected function casts(): array
    {
        return ['artifact_identity' => 'array', 'attempts' => 'integer', 'heartbeat_at' => 'datetime', 'verification_findings' => 'array', 'approval_expires_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
    }

    /** Artifact reservations share retention's mutex; restore execution remains unavailable. */
    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
            if (in_array($this->status, ['requested', 'verifying', 'verified', 'approved', 'restoring'], true)
                && ! BackupRun::query()->whereKey($this->backup_run_id)->where('status', 'available')->lockForUpdate()->exists()) {
                throw new BackupFailure('retention_ineligible');
            }

            return parent::save($options);
        });
    }
}
