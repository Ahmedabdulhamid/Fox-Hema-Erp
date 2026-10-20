<?php

namespace App\Modules\Inventory\Data;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;

final readonly class StockPostingCommand
{
    public function __construct(
        public ProductVariant $variant,
        public Location $location,
        public StockMovementType $type,
        public string $quantity,
        public string $operationIdentity,
        public ?Model $reference = null,
        public ?string $notes = null,
        public ?string $unitCost = null,
        public ?StockMovement $reverses = null,
        public ?string $valuationUnitCost = null,
    ) {}
}
