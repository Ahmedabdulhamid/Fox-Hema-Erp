<?php

namespace App\Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImportRow extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
