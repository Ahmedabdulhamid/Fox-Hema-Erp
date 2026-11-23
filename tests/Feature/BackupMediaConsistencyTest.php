<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\RecoverySql;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Services\ProductImageManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BackupFileEnvironment;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class BackupMediaConsistencyTest extends TestCase
{
    use BackupFileEnvironment;
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupFiles();
        app(ModuleRegistry::class)->register();
        Storage::forgetDisk('public');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->removeBackupTestDirectory($this->backupTestRoot);
        parent::tearDown();
    }

    public function test_media_snapshot_literals_are_decoded_without_live_queries(): void
    {
        $source = $this->backupTestRoot.'/snapshot.sql';
        $sql = <<<'SQL'
CREATE TABLE `product_images` (`id` bigint unsigned, `disk` varchar(20), `path` varchar(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `product_images` (`path`,`id`,`disk`) VALUES ('products/old,quote\'.png',1,'public');
CREATE TABLE `brands` (`id` bigint unsigned, `icon` varchar(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `brands` VALUES (2,0x6272616e64732f69636f6e732f6f6c642e706e67),(3,NULL),(4,'');
SQL;
        file_put_contents($source, $sql);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $references = app(RecoverySql::class)->mediaReferences($source, $this->backupTestRoot.'/validated.sql', static fn () => null);
        $this->assertSame([
            ['table' => 'product_images', 'id' => 1, 'disk' => 'public', 'path' => "products/old,quote'.png"],
            ['table' => 'brands', 'id' => 2, 'disk' => 'public', 'path' => 'brands/icons/old.png'],
        ], $references);
        $this->assertSame(0, $queries);
    }

    public function test_media_snapshot_rejects_unsupported_disks(): void
    {
        $source = $this->backupTestRoot.'/snapshot.sql';
        file_put_contents($source, "CREATE TABLE `product_images` (`id` int, `disk` varchar(20), `path` varchar(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; INSERT INTO `product_images` VALUES (1,'s3','products/image.png');");
        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('unsupported_storage');
        app(RecoverySql::class)->mediaReferences($source, $this->backupTestRoot.'/validated.sql', static fn () => null);
    }

    public function test_media_upload_collision_never_overwrites_existing_bytes(): void
    {
        $manager = app(ProductImageManager::class);
        Str::createUuidsUsing(static fn (): string => '11111111-1111-4111-8111-111111111111');
        try {
            $path = $manager->storeImmutableUpload(UploadedFile::fake()->image('original.png'), 'brands/icons');
            $hash = hash_file('sha256', Storage::disk('public')->path($path));
            try {
                $manager->storeImmutableUpload(UploadedFile::fake()->image('replacement.png', 30, 30), 'brands/icons');
                $this->fail('Existing immutable path was overwritten.');
            } catch (\RuntimeException $failure) {
                $this->assertStringContainsString('without overwriting', $failure->getMessage());
            }
            $this->assertSame($hash, hash_file('sha256', Storage::disk('public')->path($path)));
        } finally {
            Str::createUuidsNormally();
        }
    }

    public function test_media_cleanup_defers_replacement_and_pins_retained_snapshot_references(): void
    {
        $manager = app(ProductImageManager::class);
        $old = $manager->storeImmutableUpload(UploadedFile::fake()->image('old.png'), 'brands/icons');
        $brand = Brand::factory()->create(['icon' => $old]);
        $new = $manager->storeImmutableUpload(UploadedFile::fake()->image('new.png'), 'brands/icons');
        $brand->update(['icon' => $new]);
        $id = (int) DB::table('product_image_cleanup_tasks')->where('path', $old)->value('id');
        $this->assertTrue($manager->cleanup($id));
        Storage::disk('public')->assertExists($old);
        $run = BackupRun::factory()->create([...$this->availableEvidence(), 'status' => 'available', 'destination_snapshot' => [
            'media_reference_version' => 1, 'media_references' => ['files/public/'.$old],
        ]]);
        $this->travel(25)->hours();
        $this->assertTrue($manager->cleanup($id));
        Storage::disk('public')->assertExists($old);
        $this->assertDatabaseHas('product_image_cleanup_tasks', ['id' => $id]);
        $run->update(['status' => 'deleted']);
        $this->assertTrue($manager->cleanup($id));
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
        $this->assertDatabaseMissing('product_image_cleanup_tasks', ['id' => $id]);
    }

    public function test_media_cleanup_refuses_active_expired_or_unindexed_backups_and_retries(): void
    {
        Log::spy();
        $manager = app(ProductImageManager::class);
        $path = $manager->storeImmutableUpload(UploadedFile::fake()->image('orphan.png'), 'products/1');
        $id = (int) DB::table('product_image_cleanup_tasks')->where('path', $path)->value('id');
        $run = BackupRun::factory()->create(['status' => 'capturing', 'started_at' => now()->subDays(2)]);
        $this->travel(25)->hours();
        $this->assertFalse($manager->cleanup($id));
        Storage::disk('public')->assertExists($path);
        $run->update([...$this->availableEvidence(), 'status' => 'available', 'destination_snapshot' => []]);
        $this->assertFalse($manager->cleanup($id));
        $this->assertDatabaseHas('product_image_cleanup_tasks', ['id' => $id, 'attempts' => 2]);
        $run->update(['destination_snapshot' => ['media_reference_version' => 1, 'media_references' => []]]);
        $this->assertTrue($manager->cleanup($id));
        Storage::disk('public')->assertMissing($path);
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $context['reason'] === 'active_capture')->once();
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $context['reason'] === 'retained_backup_media_unindexed')->once();
    }

    public function test_media_product_deletion_is_deferred_and_live_shared_references_are_preserved(): void
    {
        $manager = app(ProductImageManager::class);
        $path = $manager->storeImmutableUpload(UploadedFile::fake()->image('product.png'), 'products/1');
        $image = ProductImage::factory()->create(['path' => $path]);
        $shared = ProductImage::factory()->create(['path' => $path]);
        $image->delete();
        Storage::disk('public')->assertExists($path);
        $id = (int) DB::table('product_image_cleanup_tasks')->where('path', $path)->value('id');
        $this->travel(25)->hours();
        $this->assertTrue($manager->cleanup($id));
        Storage::disk('public')->assertExists($path);
        $shared->delete();
        $this->travel(25)->hours();
        $id = (int) DB::table('product_image_cleanup_tasks')->where('path', $path)->value('id');
        $this->assertTrue($manager->cleanup($id));
        Storage::disk('public')->assertMissing($path);
    }

    /** @return array<string, mixed> */
    private function availableEvidence(): array
    {
        return ['verification_status' => 'checksum_verified', 'verified_at' => now(), 'completed_at' => now(),
            'object_key' => 'synthetic/fixture.fhbk', 'size_bytes' => 1, 'ciphertext_checksum' => str_repeat('a', 64), 'manifest_checksum' => str_repeat('b', 64)];
    }
}
