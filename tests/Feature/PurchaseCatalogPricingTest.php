<?php

namespace Tests\Feature;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Services\PurchaseLinePricing;
use App\Modules\Taxes\Models\Tax;
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

    public static function unsupported(): array
    {
        return [[['rate' => '14']], [['type' => 'fixed']], [['price_inclusion' => 'included']]];
    }

    #[DataProvider('unsupported')]
    public function test_undefined_purchase_tax_and_cost_policies_are_rejected(array $attributes): void
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

    public function test_inactive_and_out_of_date_purchase_taxes_are_not_applicable(): void
    {
        foreach ([['is_active' => false], ['effective_to' => now()->subDay()->toDateString()],
            ['effective_from' => now()->addDay()->toDateString()]] as $attributes) {
            $this->variant->product->taxes()->attach(Tax::factory()->create($attributes + ['usage' => 'purchases', 'rate' => '14']));
        }
        $this->assertSame([], $this->price()['commercial_snapshot']['taxes']);
    }
}
