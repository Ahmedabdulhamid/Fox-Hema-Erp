<?php

namespace App\Modules\BackupRestore\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\BackupRestore\Database\Factories\BackupRunFactory;
use App\Modules\BackupRestore\Services\BackupFailure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class BackupRun extends Model
{
    use HasFactory;

    protected static function newFactory(): BackupRunFactory
    {
        return BackupRunFactory::new();
    }

    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['destination_snapshot'];

    protected function casts(): array
    {
        return ['destination_snapshot' => 'array', 'legal_hold' => 'boolean', 'started_at' => 'datetime', 'captured_at' => 'datetime', 'completed_at' => 'datetime', 'verified_at' => 'datetime', 'retain_until' => 'datetime', 'size_bytes' => 'integer', 'file_count' => 'integer'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(BackupPolicy::class, 'policy_id');
    }

    public function restores(): HasMany
    {
        return $this->hasMany(BackupRestoreRun::class);
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists || ! $this->isDirty(['legal_hold', 'retain_until'])) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options): bool {
            DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
            if (self::query()->whereKey($this->getKey())->whereIn('status', ['deleting', 'deletion_failed', 'deleted'])->lockForUpdate()->exists()) {
                throw new BackupFailure('retention_ineligible');
            }

            return parent::save($options);
        });
    }
}
