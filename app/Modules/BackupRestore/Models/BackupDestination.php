<?php

namespace App\Modules\BackupRestore\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\BackupRestore\Database\Factories\BackupDestinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupDestination extends Model
{
    use HasFactory;

    protected static function newFactory(): BackupDestinationFactory
    {
        return BackupDestinationFactory::new();
    }

    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['access_key_id', 'secret_access_key'];

    protected function casts(): array
    {
        return ['access_key_id' => 'encrypted', 'secret_access_key' => 'encrypted', 'configuration_version' => 'integer', 'last_checked_at' => 'datetime'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class, 'destination_id');
    }
}
