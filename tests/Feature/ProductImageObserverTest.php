<?php

namespace Tests\Feature;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class ProductImageObserverTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function image(array $attributes = []): ProductImage
    {
        $image = ProductImage::factory()->create($attributes);
        Storage::disk('public')->put($image->path, 'image');

        return $image;
    }

    public function test_replacement_deletes_old_file_only_after_commit(): void
    {
        $image = $this->image();
        $oldPath = $image->path;
        $newPath = 'products/replacement.png';
        Storage::disk('public')->put($newPath, 'replacement');

        DB::transaction(function () use ($image, $oldPath, $newPath): void {
            $image->update(['path' => $newPath]);
            Storage::disk('public')->assertExists($oldPath);
        });

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
    }

    public function test_metadata_updates_preserve_the_file(): void
    {
        $image = $this->image();
        $image->update(['alt_text' => 'Updated description', 'is_primary' => true]);

        Storage::disk('public')->assertExists($image->path);
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
    }

    public function test_shared_files_are_deleted_only_after_the_last_image_is_deleted(): void
    {
        $first = $this->image();
        $second = $this->image(['path' => $first->path]);
        $first->delete();
        Storage::disk('public')->assertExists($second->path);

        $second->delete();
        Storage::disk('public')->assertMissing($second->path);
    }

    public function test_product_deletion_removes_all_images_even_with_a_stale_loaded_relation(): void
    {
        $product = Product::factory()->create();
        $product->load('images');
        $general = $this->image(['product_id' => $product->id]);
        $other = $this->image(['product_id' => $product->id]);

        $product->delete();

        Storage::disk('public')->assertMissing([$general->path, $other->path]);
        $this->assertDatabaseMissing('product_images', ['product_id' => $product->id]);
    }

    public function test_failed_product_deletion_preserves_its_images(): void
    {
        $variant = ProductVariant::factory()->create();
        $image = $this->image(['product_id' => $variant->product_id, 'product_variant_id' => $variant->id]);

        try {
            $variant->product->delete();
            $this->fail('Products with variants must not be deleted.');
        } catch (QueryException $exception) {
            Storage::disk('public')->assertExists($image->path);
            $this->assertDatabaseHas('product_images', ['id' => $image->id]);
            $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
        }
    }

    public function test_rolled_back_replacement_and_deletion_preserve_original_files_and_records(): void
    {
        $image = $this->image();
        $oldPath = $image->path;

        DB::beginTransaction();
        $image->update(['path' => 'products/rolled-back.png']);
        DB::rollBack();

        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame($oldPath, $image->fresh()->path);

        DB::beginTransaction();
        $image->product->delete();
        DB::rollBack();

        Storage::disk('public')->assertExists($oldPath);
        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
    }
}
