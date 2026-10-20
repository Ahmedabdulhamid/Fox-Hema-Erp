<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Products\Filament\Resources\Brands\Pages\CreateBrand;
use App\Modules\Products\Filament\Resources\Brands\Pages\EditBrand;
use App\Modules\Products\Filament\Resources\Brands\Pages\ViewBrand;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Services\ProductCatalogManager;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class BrandIconTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function brand(string $path): Brand
    {
        Storage::disk('public')->put($path, 'image');

        return Brand::factory()->create(['icon' => $path]);
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('brand.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    public function test_replacing_an_icon_deletes_the_old_file_after_commit(): void
    {
        $brand = $this->brand('brands/icons/old.png');
        Storage::disk('public')->put('brands/icons/new.png', 'new');
        DB::transaction(function () use ($brand): void {
            $brand->update(['icon' => 'brands/icons/new.png']);
            Storage::disk('public')->assertExists('brands/icons/old.png');
        });

        Storage::disk('public')->assertMissing('brands/icons/old.png');
        Storage::disk('public')->assertExists('brands/icons/new.png');
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
    }

    public function test_icon_removal_and_brand_deletion_remove_unused_files(): void
    {
        $brand = $this->brand('brands/icons/remove.png');
        $brand->update(['icon' => null]);
        Storage::disk('public')->assertMissing('brands/icons/remove.png');

        $other = $this->brand('brands/icons/delete.png');
        $other->delete();
        Storage::disk('public')->assertMissing('brands/icons/delete.png');
    }

    public function test_shared_icons_are_preserved_until_the_last_reference_is_deleted(): void
    {
        $first = $this->brand('brands/icons/shared.png');
        $second = $this->brand('brands/icons/shared.png');
        $first->delete();
        Storage::disk('public')->assertExists($second->icon);
        $second->delete();
        Storage::disk('public')->assertMissing('brands/icons/shared.png');
    }

    public function test_product_image_references_also_preserve_shared_brand_files(): void
    {
        $brand = $this->brand('brands/icons/product-shared.png');
        $image = ProductImage::factory()->create(['path' => $brand->icon]);
        $brand->delete();
        Storage::disk('public')->assertExists($image->path);
        $image->delete();
        Storage::disk('public')->assertMissing('brands/icons/product-shared.png');
    }

    public function test_rollback_preserves_the_original_icon_and_database_record(): void
    {
        $brand = $this->brand('brands/icons/rollback.png');
        DB::beginTransaction();
        $brand->update(['icon' => null]);
        DB::rollBack();
        Storage::disk('public')->assertExists('brands/icons/rollback.png');
        $this->assertSame('brands/icons/rollback.png', $brand->fresh()->icon);

        DB::beginTransaction();
        $brand->fresh()->delete();
        DB::rollBack();
        Storage::disk('public')->assertExists('brands/icons/rollback.png');
        $this->assertModelExists($brand);
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
    }

    public function test_metadata_updates_do_not_delete_the_icon_and_unsafe_paths_are_preserved(): void
    {
        $brand = $this->brand('brands/icons/metadata.png');
        $brand->update(['name' => 'Updated brand']);
        Storage::disk('public')->assertExists($brand->icon);
        $unsafe = $this->brand('private/keep.png');
        $unsafe->delete();
        Storage::disk('public')->assertExists('private/keep.png');
        $this->assertDatabaseHas('product_image_cleanup_tasks', ['path' => 'private/keep.png', 'attempts' => 1]);
    }

    public function test_filament_can_create_view_replace_and_remove_brand_icons(): void
    {
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'products')->update(['is_active' => true]);
        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateBrand::class)->fillForm(['name' => 'Icon brand', 'icon' => $this->upload()])->call('create')->assertHasNoFormErrors();
        $brand = Brand::where('name', 'Icon brand')->firstOrFail();
        $oldPath = $brand->icon;
        $this->assertStringStartsWith('brands/icons/', $oldPath);
        Storage::disk('public')->assertExists($oldPath);
        Livewire::test(ViewBrand::class, ['record' => $brand->slug])->assertSee(Storage::disk('public')->url($oldPath), false);

        Livewire::test(EditBrand::class, ['record' => $brand->slug])->fillForm(['icon' => [$this->upload()]])->call('save')->assertHasNoFormErrors();
        $newPath = $brand->fresh()->icon;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
        Livewire::test(EditBrand::class, ['record' => $brand->slug])->fillForm(['icon' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($brand->fresh()->icon);
        Storage::disk('public')->assertMissing($newPath);

        Storage::disk('public')->put('private/not-a-brand.png', 'private');
        $this->expectException(ValidationException::class);
        app(ProductCatalogManager::class)->save($admin, Brand::class, ['name' => 'Forged', 'icon' => 'private/not-a-brand.png'], $brand);
    }
}
