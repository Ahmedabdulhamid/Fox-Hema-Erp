<?php

namespace App\Modules\InventoryCosting\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\InventoryCosting\Database\Factories\InventoryValuationSettingFactory;
use App\Modules\Products\Models\Category;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryValuationSetting extends Model
{
    /** @use HasFactory<InventoryValuationSettingFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = ['category_id', 'costing_method', 'note'];

    protected static function newFactory(): InventoryValuationSettingFactory
    {
        return InventoryValuationSettingFactory::new();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
