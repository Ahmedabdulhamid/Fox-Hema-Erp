<?php

namespace Tests\Feature;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseLinePricing;
use App\Modules\Taxes\Models\Tax;
use App\Modules\Taxes\Services\CommercialDefinitions;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PurchaseCatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create()->id]);
    }

    private function price(array $input = []): array
    {
        return app(PurchaseLinePricing::class)->line($this->variant, $input + ['quantity' => '3.3333', 'unit_price' => '10.123456']);
    }

    public function test_customer_promotions_and_sales_taxes_never_apply_to_a_purchase(): void
    {
        $this->variant->product->discounts()->attach(Discount::factory()->create(['value' => '50']));
        $this->variant->product->taxes()->attach(Tax::factory()->create(['usage' => 'sales', 'rate' => '14']));
        $line = $this->price();
        $this->assertSame('33.7445', $line['subtotal']);
        $this->assertSame('33.7445', $line['line_total']);
        $this->assertSame('0.0000', $line['discount_amount']);
        $this->assertSame('0.0000', $line['tax_amount']);
        $this->assertSame([], $line['commercial_snapshot']['taxes']);
    }

    public function test_purchase_and_both_zero_rated_definitions_are_snapshotted(): void
    {
        foreach (['purchases', 'both'] as $usage) {
            $this->variant->product->taxes()->attach(Tax::factory()->create(['usage' => $usage, 'rate' => '0']));
        }
        $line = $this->price();
        $this->assertCount(2, $line['commercial_snapshot']['taxes']);
        $this->assertSame('0.0000', $line['tax_amount']);
        $this->assertSame('10.123456', $line['unit_price']);
    }

    public function test_fixed_purchase_discount_and_tax_are_once_per_line_and_percentage_tax_uses_the_discounted_basis(): void
    {
        $discount = Discount::factory()->create(['type' => 'fixed', 'usage' => 'purchases', 'value' => '5.0000']);
        $fixedTax = Tax::factory()->create(['type' => 'fixed', 'usage' => 'purchases', 'rate' => '2.5000', 'purchase_treatment' => 'recoverable']);
        $percentageTax = Tax::factory()->create(['usage' => 'purchases', 'rate' => '14.0000', 'purchase_treatment' => 'recoverable']);
        $input = ['discount_id' => $discount->id, 'tax_ids' => [$fixedTax->id, $percentageTax->id]];

        $line = $this->price($input);
        $singleUnit = $this->price($input + ['quantity' => '1']);

        $this->assertSame('5.0000', $line['discount_amount']);
        $this->assertSame('6.5242', $line['tax_amount']);
        $this->assertSame('35.2687', $line['line_total']);
        $this->assertSame('5.0000', $singleUnit['discount_amount']);
        $this->assertSame('3.2173', $singleUnit['tax_amount']);
        $this->assertSame('8.3408', $singleUnit['line_total']);
        $this->assertSame('2.5000', $line['commercial_snapshot']['taxes'][0]['amount']);
        $this->assertSame('2.5000', $singleUnit['commercial_snapshot']['taxes'][0]['amount']);
        $this->assertSame('purchase-excluded-line-fixed-amounts-and-percentage-after-discount-half-up-4', $line['commercial_snapshot']['policy']);

        app(PurchaseLinePricing::class)->assertPostable($line);
    }

    public function test_fixed_purchase_discount_cannot_exceed_the_line_subtotal(): void
    {
        $discount = Discount::factory()->create(['type' => 'fixed', 'usage' => 'purchases', 'value' => '40.0000']);

        $this->expectException(ValidationException::class);
        $this->price(['discount_id' => $discount->id]);
    }

    public static function unsupported(): array
    {
        return [[['rate' => '14']], [['price_inclusion' => 'included']]];
    }

    #[DataProvider('unsupported')]
    public function test_nonzero_purchase_tax_and_included_tax_are_not_postable(array $attributes): void
    {
        $this->variant->product->taxes()->attach(Tax::factory()->create($attributes + ['usage' => 'purchases']));
        $this->expectException(ValidationException::class);
        app(PurchaseLinePricing::class)->assertPostable($this->price());
    }

    public static function forged(): array
    {
        return array_map(fn (array $input): array => [$input], [['tax_amount' => '1'], ['discount_amount' => '1'],
            ['tax_id' => 1], ['tax_ids' => [1]], ['tax_rate' => '14'], ['discount_id' => 1], ['supplier_discount_id' => 1], ['commercial_snapshot' => []]]);
    }

    #[DataProvider('forged')]
    public function test_purchasing_never_accepts_forged_definitions_or_manual_amounts(array $input): void
    {
        $this->expectException(ValidationException::class);
        $this->price($input);
    }

    public function test_inactive_and_out_of_date_purchase_taxes_and_discounts_are_not_applicable(): void
    {
        foreach ([['is_active' => false], ['effective_to' => now()->subDay()->toDateString()],
            ['effective_from' => now()->addDay()->toDateString()]] as $attributes) {
            $this->variant->product->taxes()->attach(Tax::factory()->create($attributes + ['usage' => 'purchases', 'rate' => '14']));
        }
        foreach ([['is_active' => false], ['ends_at' => now()->subSecond()], ['starts_at' => now()->addSecond()]] as $attributes) {
            $this->variant->product->discounts()->attach(Discount::factory()->create($attributes + ['usage' => 'purchases', 'value' => '10']));
        }
        $this->assertSame([], $this->price()['commercial_snapshot']['taxes']);
        $this->assertSame('0.0000', $this->price()['discount_amount']);
    }

    public function test_purchase_validity_dates_include_their_current_boundaries(): void
    {
        $tax = Tax::factory()->create(['type' => 'fixed', 'usage' => 'purchases', 'rate' => '2.5000',
            'effective_from' => now()->toDateString(), 'effective_to' => now()->toDateString()]);
        $discount = Discount::factory()->create(['type' => 'fixed', 'usage' => 'purchases', 'value' => '5.0000',
            'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->toDateTimeString()]);
        $line = $this->price(['tax_ids' => [$tax->id], 'discount_id' => $discount->id]);

        $this->assertSame('5.0000', $line['discount_amount']);
        $this->assertSame('2.5000', $line['tax_amount']);
    }

    public function test_supplier_specific_discount_rejects_a_different_supplier_and_generic_discount_remains_eligible(): void
    {
        $supplier = Supplier::factory()->create();
        $otherSupplier = Supplier::factory()->create();
        $specific = Discount::factory()->create(['type' => 'fixed', 'usage' => 'purchases',
            'value' => '5.0000', 'supplier_id' => $supplier->id]);
        $generic = Discount::factory()->create(['type' => 'fixed', 'usage' => 'purchases',
            'value' => '3.0000', 'supplier_id' => null]);
        $this->variant->product->discounts()->attach([$specific->id, $generic->id]);

        $eligible = app(CommercialDefinitions::class)
            ->selected('discounts', 'purchases', [$specific->id, $generic->id], $supplier->id);
        $this->assertTrue($eligible->contains('id', $specific->id));
        $this->assertTrue($eligible->contains('id', $generic->id));

        $this->expectException(ValidationException::class);
        app(PurchaseLinePricing::class)->line($this->variant,
            ['quantity' => '3.3333', 'unit_price' => '10.123456', 'discount_id' => $specific->id],
            supplierId: $otherSupplier->id);
    }

    public function test_fixed_discount_and_tax_are_allocated_cumulatively_across_partial_receipts(): void
    {
        $snapshot = [
            'discount' => ['type' => 'fixed', 'value' => '100.0000'],
            'taxes' => [
                ['type' => 'fixed', 'rate' => '50.0000', 'price_inclusion' => 'excluded',
                    'purchase_treatment' => 'capitalizable'],
            ],
        ];
        $pricing = app(PurchaseLinePricing::class);
        $first = $pricing->allocate($snapshot, '2', '500', '10', '0');
        $second = $pricing->allocate($snapshot, '8', '500', '10', '2');

        $this->assertSame('20.0000', $first['discount_amount']);
        $this->assertSame('80.0000', $second['discount_amount']);
        $this->assertSame('10.0000', $first['tax_amount']);
        $this->assertSame('40.0000', $second['tax_amount']);
        $this->assertSame('10.0000', $first['commercial_snapshot']['capitalizable_tax_amount']);
        $this->assertSame('40.0000', $second['commercial_snapshot']['capitalizable_tax_amount']);
    }
}
