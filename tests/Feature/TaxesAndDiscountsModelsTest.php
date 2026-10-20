<?php

namespace Tests\Feature;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Models\DiscountProduct;
use App\Modules\Products\Models\Product;
use App\Modules\Taxes\Models\ProductTax;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Support\Carbon;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class TaxesAndDiscountsModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        foreach (['Products', 'Taxes', 'Discounts'] as $module) {
            $this->app->make('migrator')->path(app_path("Modules/{$module}/Database/Migrations"));
        }
    }

    public function test_product_taxes_support_both_directions_custom_pivots_and_detaching(): void
    {
        $product = Product::create(['name' => 'First product']);
        $otherProduct = Product::create(['name' => 'Second product']);
        $tax = Tax::factory()->create();
        Tax::factory()->create();
        $product->taxes()->attach($tax);
        $tax->products()->attach($otherProduct);

        $product = $product->fresh(['taxes', 'productTaxes.tax', 'productTaxes.product']);
        $this->assertSame([$tax->id], $product->taxes->modelKeys());
        $this->assertInstanceOf(ProductTax::class, $product->taxes->first()->pivot);
        $pivot = $product->taxes->first()->pivot;
        $this->assertIsInt($pivot->id);
        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
        $this->assertTrue($pivot->product->is($product));
        $this->assertTrue($pivot->tax->is($tax));
        $this->assertTrue($product->productTaxes->first()->tax->is($tax));
        $this->assertEqualsCanonicalizing([$product->id, $otherProduct->id], $tax->fresh(['products'])->products->modelKeys());
        $this->assertCount(2, $tax->productTaxes);

        $product->taxes()->detach($tax);
        $this->assertTrue($product->fresh(['taxes', 'productTaxes'])->taxes->isEmpty());
        $this->assertTrue($product->fresh(['productTaxes'])->productTaxes->isEmpty());
        $this->assertSame([$otherProduct->id], $tax->fresh(['products'])->products->modelKeys());
    }

    public function test_product_discounts_support_both_directions_custom_pivots_and_syncing(): void
    {
        $product = Product::create(['name' => 'First product']);
        $otherProduct = Product::create(['name' => 'Second product']);
        $discount = Discount::factory()->create();
        $otherDiscount = Discount::factory()->create();
        $product->discounts()->attach($discount);
        $discount->products()->attach($otherProduct);

        $product = $product->fresh(['discounts', 'discountProducts.discount', 'discountProducts.product']);
        $this->assertSame([$discount->id], $product->discounts->modelKeys());
        $pivot = $product->discounts->first()->pivot;
        $this->assertInstanceOf(DiscountProduct::class, $pivot);
        $this->assertIsInt($pivot->id);
        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
        $this->assertTrue($pivot->product->is($product));
        $this->assertTrue($pivot->discount->is($discount));
        $this->assertTrue($product->discountProducts->first()->discount->is($discount));
        $this->assertEqualsCanonicalizing([$product->id, $otherProduct->id], $discount->fresh(['products'])->products->modelKeys());
        $this->assertCount(2, $discount->discountProducts);

        $product->discounts()->sync([$otherDiscount->id]);
        $this->assertSame([$otherDiscount->id], $product->fresh(['discounts'])->discounts->modelKeys());
        $this->assertSame([$otherProduct->id], $discount->fresh(['products'])->products->modelKeys());
        $this->assertSame([$product->id], $otherDiscount->fresh(['products'])->products->modelKeys());
    }

    public function test_link_models_can_be_created_directly_and_load_their_owners(): void
    {
        $productTax = ProductTax::factory()->create()->fresh(['product', 'tax']);
        $discountProduct = DiscountProduct::factory()->create([
            'product_id' => $productTax->product_id,
        ])->fresh(['product', 'discount']);
        $this->assertIsInt($productTax->id);
        $this->assertIsInt($discountProduct->id);
        $this->assertSame($productTax->product_id, $productTax->product->id);
        $this->assertSame($productTax->tax_id, $productTax->tax->id);
        $this->assertTrue($discountProduct->product->is($productTax->product));
        $this->assertSame($discountProduct->discount_id, $discountProduct->discount->id);
        $this->assertSame([$productTax->id], $productTax->product->productTaxes->modelKeys());
        $this->assertSame([$discountProduct->id], $discountProduct->product->discountProducts->modelKeys());
        $this->assertSame([$productTax->id], $productTax->tax->productTaxes->modelKeys());
        $this->assertSame([$discountProduct->id], $discountProduct->discount->discountProducts->modelKeys());
    }

    public function test_rates_values_flags_and_effective_dates_use_the_correct_casts(): void
    {
        $tax = Tax::factory()->create([
            'rate' => '12.1234',
            'is_active' => false,
            'effective_from' => '2026-10-10',
            'effective_to' => '2026-11-10',
        ])->fresh();
        $discount = Discount::factory()->create([
            'value' => '123.4567',
            'is_active' => false,
            'is_stackable' => true,
            'starts_at' => '2026-10-10 12:30:45',
            'ends_at' => '2026-11-10 13:45:30',
        ])->fresh();
        $this->assertSame('12.1234', $tax->rate);
        $this->assertFalse($tax->is_active);
        $this->assertSame('123.4567', $discount->value);
        $this->assertFalse($discount->is_active);
        $this->assertTrue($discount->is_stackable);
        $this->assertInstanceOf(Carbon::class, $tax->effective_from);
        $this->assertInstanceOf(Carbon::class, $tax->effective_to);
        $this->assertSame('2026-10-10', $tax->effective_from->toDateString());
        $this->assertSame('2026-11-10', $tax->effective_to->toDateString());
        $this->assertInstanceOf(Carbon::class, $discount->starts_at);
        $this->assertInstanceOf(Carbon::class, $discount->ends_at);
        $this->assertSame('2026-10-10 12:30:45', $discount->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 13:45:30', $discount->ends_at->format('Y-m-d H:i:s'));

        $tax = Tax::factory()->create()->fresh();
        $discount = Discount::factory()->create(['code' => null])->fresh();
        $this->assertNull($tax->effective_from);
        $this->assertNull($tax->effective_to);
        $this->assertNull($discount->starts_at);
        $this->assertNull($discount->ends_at);
        $this->assertNull($discount->code);
    }
}
