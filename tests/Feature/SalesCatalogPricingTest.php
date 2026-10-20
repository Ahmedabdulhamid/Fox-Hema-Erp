<?php

namespace Tests\Feature;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Services\SalesLinePricing;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SalesCatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $product = Product::factory()->create(['has_fixed_selling_price' => false]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sale_price' => '10.1234']);
    }

    private function price(array $input = []): array
    {
        return app(SalesLinePricing::class)->line($this->variant, $input + ['quantity' => '3']);
    }

    public function test_percentage_discount_and_multiple_taxes_use_exact_net_basis(): void
    {
        $discount = Discount::factory()->create(['value' => '10']);
        $this->variant->product->discounts()->attach($discount);
        foreach (['14', '2.5'] as $rate) {
            $this->variant->product->taxes()->attach(Tax::factory()->create(['rate' => $rate]));
        }
        $line = $this->price();
        $this->assertSame('30.3702', $line['subtotal']);
        $this->assertSame('3.0370', $line['discount_amount']);
        $this->assertSame('4.5099', $line['tax_amount']);
        $this->assertSame('31.8431', $line['line_total']);
        $this->assertSame($discount->id, $line['commercial_snapshot']['discount']['id']);
        $this->assertCount(2, $line['commercial_snapshot']['taxes']);
        $this->assertSame('27.3332', $line['commercial_snapshot']['taxes'][0]['basis']);
        $this->assertSame('10.6145', $this->price(['quantity' => '1'])['line_total']);
    }

    public function test_inactive_expired_future_purchase_only_taxes_and_order_discounts_are_ineligible(): void
    {
        foreach ([['is_active' => false], ['effective_to' => now()->subDay()->toDateString()],
            ['effective_from' => now()->addDay()->toDateString()], ['usage' => 'purchases']] as $attributes) {
            $this->variant->product->taxes()->attach(Tax::factory()->create($attributes + ['rate' => '14']));
        }
        foreach ([['is_active' => false], ['ends_at' => now()->subSecond()], ['starts_at' => now()->addSecond()], ['scope' => 'order']] as $attributes) {
            $this->variant->product->discounts()->attach(Discount::factory()->create($attributes));
        }
        $line = $this->price();
        $this->assertSame('0.0000', $line['tax_amount']);
        $this->assertSame('0.0000', $line['discount_amount']);
        $this->assertSame('30.3702', $line['line_total']);
    }

    public function test_ambiguous_discounts_require_selection_and_never_stack(): void
    {
        $discounts = Discount::factory()->count(2)->create(['value' => '10']);
        $this->variant->product->discounts()->attach($discounts->modelKeys());
        try {
            $this->price();
            $this->fail('An explicit choice is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('discount_id', $exception->errors());
        }
        $this->assertSame('3.0370', $this->price(['discount_id' => $discounts->first()->id])['discount_amount']);
    }

    public static function forgedInputs(): array
    {
        return array_map(fn (array $input): array => [$input], [['tax_id' => 1], ['tax_ids' => [1]], ['discount_id' => 999999],
            ['discount_id' => [1]], ['discount_ids' => [1]], ['tax_rate' => '14'], ['tax_amount' => '1'], ['discount_amount' => '1'], ['commercial_snapshot' => []]]);
    }

    #[DataProvider('forgedInputs')]
    public function test_forged_ids_and_raw_amounts_are_rejected(array $input): void
    {
        $this->expectException(ValidationException::class);
        $this->price($input);
    }

    public static function unsupportedDefinitions(): array
    {
        return [['tax', ['type' => 'fixed']], ['tax', ['price_inclusion' => 'included']],
            ['discount', ['type' => 'fixed']], ['discount', ['is_stackable' => true]]];
    }

    #[DataProvider('unsupportedDefinitions')]
    public function test_undefined_financial_policies_are_blocked(string $kind, array $attributes): void
    {
        if ($kind === 'tax') {
            $this->variant->product->taxes()->attach(Tax::factory()->create($attributes));
        } else {
            $this->variant->product->discounts()->attach(Discount::factory()->create($attributes));
        }
        $this->expectException(ValidationException::class);
        $this->price();
    }

    public function test_one_hundred_percent_discount_never_produces_negative_totals(): void
    {
        $this->variant->product->discounts()->attach(Discount::factory()->create(['value' => '100']));
        $this->variant->product->taxes()->attach(Tax::factory()->create(['rate' => '14']));
        $this->assertSame('0.0000', $this->price()['line_total']);
        $this->assertSame('0.0000', $this->price()['tax_amount']);
    }
}
