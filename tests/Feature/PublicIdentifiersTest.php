<?php

namespace Tests\Feature;

use App\Models\PublicIdentifierMigration;
use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Filament\Resources\Locations\LocationResource;
use App\Modules\AccessManagement\Filament\Resources\Permissions\PermissionResource;
use App\Modules\AccessManagement\Filament\Resources\Roles\RoleResource;
use App\Modules\AccessManagement\Filament\Resources\Users\UserResource;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Products\Filament\Resources\Brands\BrandResource;
use App\Modules\Products\Filament\Resources\Categories\CategoryResource;
use App\Modules\Products\Filament\Resources\Colors\ColorResource;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Filament\Resources\Sizes\SizeResource;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\Size;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class PublicIdentifiersTest extends TestCase
{
    use UsesIsolatedDatabase;

    public function test_catalog_slugs_are_unique_stable_and_support_non_latin_names(): void
    {
        $first = Product::factory()->create(['name' => 'Blue Shirt']);
        $second = Product::factory()->create(['name' => 'Blue Shirt']);
        $this->assertSame('blue-shirt', $first->slug);
        $this->assertNotSame($first->slug, $second->slug);
        $first->update(['name' => 'Renamed shirt']);
        $this->assertSame('blue-shirt', $first->fresh()->slug);

        $arabic = Product::factory()->create(['name' => 'قميص']);
        $this->assertNotEmpty($arabic->slug);
        $this->assertSame($arabic->slug, $arabic->getRouteKey());
    }

    public function test_users_keep_numeric_primary_keys_and_spatie_relationships_with_unique_uuids(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->assertIsInt($first->id);
        $this->assertSame('id', $first->getKeyName());
        $this->assertTrue($first->getIncrementing());
        $this->assertTrue(Str::isUuid($first->uuid));
        $this->assertTrue(Str::isUuid($second->uuid));
        $this->assertNotSame($first->uuid, $second->uuid);

        $location = Location::create(['name' => 'Public ID branch', 'type' => 'branch', 'is_active' => true]);
        setPermissionsTeamId($location->id);
        $role = Role::create(['name' => 'catalog-reader', 'guard_name' => 'web', 'location_id' => $location->id]);
        $permission = Permission::create(['name' => 'products.view', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $first->assignRole($role);
        $first->locations()->attach($location);
        $this->assertTrue($first->hasPermissionTo('products.view'));
        $this->assertDatabaseHas('model_has_roles', ['role_id' => $role->id, 'model_id' => $first->id]);
        $this->assertDatabaseHas('location_user', ['location_id' => $location->id, 'user_id' => $first->id]);
        $uuid = $first->uuid;
        $first->update(['name' => 'New name']);
        $this->assertSame($uuid, $first->fresh()->uuid);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $second->id)->update(['uuid' => $first->uuid]);
    }

    public function test_backfill_preserves_rows_foreign_keys_existing_values_and_timestamps(): void
    {
        Schema::create('legacy_catalog_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('slug')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('legacy_catalog_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->constrained('legacy_catalog_records');
        });
        $uuid = (string) Str::uuid();
        DB::table('legacy_catalog_records')->insert([
            ['id' => 10, 'name' => 'Duplicate', 'uuid' => null, 'slug' => null, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
            ['id' => 20, 'name' => 'Duplicate', 'uuid' => $uuid, 'slug' => 'existing-link', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
            ['id' => 30, 'name' => 'Duplicate', 'uuid' => null, 'slug' => null, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
        ]);
        DB::table('legacy_catalog_links')->insert(['id' => 5, 'record_id' => 10]);
        PublicIdentifierMigration::up(['legacy_catalog_records' => true]);
        $before = DB::table('legacy_catalog_records')->orderBy('id')->get()->toJson();
        PublicIdentifierMigration::up(['legacy_catalog_records' => true]);
        $this->assertSame($before, DB::table('legacy_catalog_records')->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('legacy_catalog_records', ['id' => 20, 'uuid' => $uuid, 'slug' => 'existing-link', 'updated_at' => '2020-01-01 00:00:00']);
        $this->assertDatabaseHas('legacy_catalog_links', ['id' => 5, 'record_id' => 10]);
        $this->assertSame(3, DB::table('legacy_catalog_records')->distinct()->count('slug'));
        $this->assertSame(3, DB::table('legacy_catalog_records')->distinct()->count('uuid'));
    }

    public function test_infrastructure_and_pivot_tables_do_not_receive_public_identifiers(): void
    {
        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'location_user', 'product_image_cleanup_tasks'] as $table) {
            if ($table !== 'failed_jobs') {
                $this->assertFalse(Schema::hasColumn($table, 'uuid'), $table);
            }
            $this->assertFalse(Schema::hasColumn($table, 'slug'), $table);
        }
    }

    public function test_additive_columns_backfill_existing_rows_and_enforce_unique_indexes(): void
    {
        Schema::create('legacy_named_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('legacy_named_entities')->insert([
            ['id' => 41, 'name' => 'Existing entity'],
            ['id' => 52, 'name' => 'Existing entity'],
        ]);

        PublicIdentifierMigration::up(['legacy_named_entities' => true]);

        $this->assertSame([41, 52], DB::table('legacy_named_entities')->orderBy('id')->pluck('id')->all());
        $this->assertSame(2, DB::table('legacy_named_entities')->distinct()->count('uuid'));
        $this->assertSame(2, DB::table('legacy_named_entities')->distinct()->count('slug'));
        foreach (['uuid', 'slug'] as $column) {
            $this->assertTrue(collect(Schema::getIndexes('legacy_named_entities'))->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === [$column]));
        }

        $this->expectException(QueryException::class);
        DB::table('legacy_named_entities')->where('id', 52)->update(['slug' => DB::table('legacy_named_entities')->where('id', 41)->value('slug')]);
    }

    public function test_filament_uses_slug_and_uuid_routes_with_real_binding(): void
    {
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'products')->update(['is_active' => true]);
        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $records = [
            ProductResource::class => Product::factory()->create(['name' => 'Public product']),
            BrandResource::class => Brand::factory()->create(),
            CategoryResource::class => Category::factory()->create(),
            ColorResource::class => Color::factory()->create(),
            SizeResource::class => Size::factory()->create(),
            UserResource::class => User::factory()->create(),
            LocationResource::class => Location::create(['name' => 'Public branch', 'type' => 'branch', 'is_active' => true]),
            RoleResource::class => Role::create(['name' => 'public-role', 'guard_name' => 'web', 'location_id' => null]),
            PermissionResource::class => Permission::where('name', 'products.view')->firstOrFail(),
        ];
        foreach ($records as $resource => $record) {
            $bound = $resource::resolveRecordRouteBinding($record->getRouteKey());
            $this->assertNotNull($bound, $resource);
            $this->assertTrue($bound->is($record));
            $page = in_array($resource, [RoleResource::class, LocationResource::class], true) ? 'edit' : 'view';
            $this->assertStringContainsString('/'.$record->getRouteKey(), $resource::getUrl($page, ['record' => $record]));
        }
        $product = $records[ProductResource::class];
        $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk();
        $this->get(UserResource::getUrl('view', ['record' => $records[UserResource::class]]))->assertOk();
        $this->get(ProductResource::getUrl('view', ['record' => 'missing-product']))->assertNotFound();
        $this->get(ProductResource::getUrl('view', ['record' => $product->id]))->assertNotFound();
    }
}
