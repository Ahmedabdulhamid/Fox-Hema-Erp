<?php

namespace App\Modules\Products\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class ProductExport extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'lease_until' => 'datetime', 'purged_at' => 'datetime'];
    }
}
