<?php

namespace App\Modules\BackupRestore\Models;

use App\Modules\BackupRestore\Database\Factories\BackupOperationLeaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupOperationLease extends Model
{
    use HasFactory;

    protected static function newFactory(): BackupOperationLeaseFactory
    {
        return BackupOperationLeaseFactory::new();
    }

    protected $primaryKey = 'scope';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['owner_token'];

    protected function casts(): array
    {
        return ['heartbeat_at' => 'datetime', 'expires_at' => 'datetime', 'fencing_number' => 'integer'];
    }
}
