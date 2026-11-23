<?php

namespace App\Modules\BackupRestore\Models;

use App\Modules\BackupRestore\Database\Factories\BackupPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupPolicy extends Model
{
    use HasFactory;

    protected static function newFactory(): BackupPolicyFactory
    {
        return BackupPolicyFactory::new();
    }

    public $incrementing = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'schedule_requested' => 'boolean', 'schedule_requested_at' => 'immutable_datetime', 'retention_requested' => 'boolean',
            'notification_recipients' => 'array', 'next_due_at' => 'datetime', 'configuration_version' => 'integer'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
    }
}
