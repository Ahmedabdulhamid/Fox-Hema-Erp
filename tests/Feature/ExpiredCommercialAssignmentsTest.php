<?php

namespace Tests\Feature;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ExpiredCommercialAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_unreferenced_links_are_removed_and_future_or_open_ended_links_remain(): void
    {
        $product = Product::factory()->create();
        $expiredTax = Tax::factory()->create(['effective_to' => today()->subDay()->toDateString()]);
        $futureTax = Tax::factory()->create(['effective_from' => today()->addDay()->toDateString()]);
        $openTax = Tax::factory()->create();
        $expiredDiscount = Discount::factory()->create(['ends_at' => now()->subSecond()]);
        $futureDiscount = Discount::factory()->create(['starts_at' => now()->addDay()]);
        $openDiscount = Discount::factory()->create();

        $product->taxes()->attach([$expiredTax->id, $futureTax->id, $openTax->id]);
        $product->discounts()->attach([$expiredDiscount->id, $futureDiscount->id, $openDiscount->id]);

        $this->artisan('commercial:prune-expired-assignments')->assertSuccessful();

        $this->assertDatabaseMissing('product_taxes', ['product_id' => $product->id, 'tax_id' => $expiredTax->id]);
        $this->assertDatabaseHas('product_taxes', ['product_id' => $product->id, 'tax_id' => $futureTax->id]);
        $this->assertDatabaseHas('product_taxes', ['product_id' => $product->id, 'tax_id' => $openTax->id]);
        $this->assertDatabaseMissing('discount_products', ['product_id' => $product->id, 'discount_id' => $expiredDiscount->id]);
        $this->assertDatabaseHas('discount_products', ['product_id' => $product->id, 'discount_id' => $futureDiscount->id]);
        $this->assertDatabaseHas('discount_products', ['product_id' => $product->id, 'discount_id' => $openDiscount->id]);

        $this->artisan('commercial:prune-expired-assignments')->assertSuccessful();
        $this->assertSame(2, DB::table('product_taxes')->count());
        $this->assertSame(2, DB::table('discount_products')->count());
    }

    public function test_expired_assignments_referenced_by_invoice_snapshots_are_preserved(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $tax = Tax::factory()->create(['effective_to' => today()->subDay()->toDateString()]);
        $discount = Discount::factory()->create(['ends_at' => now()->subSecond()]);
        $product->taxes()->attach($tax);
        $product->discounts()->attach($discount);
        $snapshot = ['taxes' => [['id' => $tax->id]], 'discount' => ['id' => $discount->id]];
        $item = SalesInvoiceItem::factory()->create([
            'variant_id' => $variant->id,
            'commercial_snapshot' => $snapshot,
        ]);

        $this->artisan('commercial:prune-expired-assignments')->assertSuccessful();

        $this->assertDatabaseHas('product_taxes', ['product_id' => $product->id, 'tax_id' => $tax->id]);
        $this->assertDatabaseHas('discount_products', ['product_id' => $product->id, 'discount_id' => $discount->id]);
        $this->assertSame($snapshot, $item->fresh()->commercial_snapshot);
    }

    public function test_command_processes_expired_links_in_bounded_chunks(): void
    {
        $tax = Tax::factory()->create(['effective_to' => today()->subDay()->toDateString()]);
        Product::factory()->count(105)->create()->each(fn (Product $product) => $product->taxes()->attach($tax));

        $this->artisan('commercial:prune-expired-assignments')->assertSuccessful();

        $this->assertSame(0, DB::table('product_taxes')->where('tax_id', $tax->id)->count());
    }
}
