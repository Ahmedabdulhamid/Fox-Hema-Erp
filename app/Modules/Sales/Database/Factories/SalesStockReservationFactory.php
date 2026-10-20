<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesStockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesStockReservation> */
class SalesStockReservationFactory extends Factory
{
    protected $model = SalesStockReservation::class;

    /** Making fixtures is supported; persistence must go through the reservation workflow. */
    public function definition(): array
    {
        return ['sales_order_id' => SalesOrder::factory(), 'variant_id' => ProductVariant::factory(),
            'location_id' => fn (array $attributes): int => SalesOrder::findOrFail($attributes['sales_order_id'])->location_id,
            'quantity_reserved' => '1.0000', 'quantity_consumed' => '0.0000', 'quantity_released' => '0.0000'];
    }
}
