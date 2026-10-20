<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Discounts\Filament\Resources\Discounts\Pages\CreateDiscount;
use App\Modules\Discounts\Filament\Resources\Discounts\Pages\EditDiscount;
use App\Modules\Discounts\Filament\Resources\Discounts\Pages\ListDiscounts;
use App\Modules\Discounts\Filament\Resources\Discounts\Pages\ViewDiscount;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Services\DiscountManager;
use App\Modules\Products\Filament\Resources\Products\Pages\CreateProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductAssignmentManager;
use App\Modules\Products\Services\ProductCatalogManager;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Taxes\Filament\Resources\Taxes\Pages\CreateTax;
use App\Modules\Taxes\Filament\Resources\Taxes\Pages\EditTax;
use App\Modules\Taxes\Filament\Resources\Taxes\Pages\ListTaxes;
use App\Modules\Taxes\Filament\Resources\Taxes\Pages\ViewTax;
use App\Modules\Taxes\Models\Tax;
use App\Modules\Taxes\Services\DefinitionAccess;
use App\Modules\Taxes\Services\DefinitionPermissions;
use App\Modules\Taxes\Services\TaxManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class TaxesDiscountsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'taxes', 'discounts'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public static function definitions(): array
    {
        return [
            'taxes' => ['taxes', Tax::class, TaxManager::class, CreateTax::class, EditTax::class, ViewTax::class, ListTaxes::class, 'rate'],
            'discounts' => ['discounts', Discount::class, DiscountManager::class, CreateDiscount::class, EditDiscount::class, ViewDiscount::class, ListDiscounts::class, 'value'],
        ];
    }

    private function admin(): User
    {
        $user = $this->provisionTestSuperAdmin();
        $user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($user);
        app(LocationContext::class)->setFor($user, null);

        return $user;
    }

    private function scoped(array $permissions): array
    {
        $location = Location::create(['name' => 'Definition branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($location);
        app(LocationContext::class)->setFor($user, $location->id);
        $role = Role::create(['name' => 'definition-manager', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return [$user, $location, $role];
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);

            return;
        }
        $this->fail('A forbidden mutation was accepted.');
    }

    #[DataProvider('definitions')]
    public function test_services_crud_preserves_identity_and_ignores_forged_identifiers(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        $actor = $this->admin();
        $service = app($manager);
        $definition = $service->save($actor, ['name' => 'Sample', 'code' => 'SAMPLE', $amount => '10.0000', 'uuid' => 'forged', 'slug' => 'forged']);
        $this->assertTrue(Str::isUuid($definition->uuid));
        $this->assertSame('sample', $definition->slug);
        $identity = [$definition->id, $definition->uuid, $definition->slug];
        $updated = $service->save($actor, ['name' => 'Renamed', 'is_active' => false], $definition);
        $this->assertSame($identity, [$updated->id, $updated->uuid, $updated->slug]);
        $this->assertFalse($updated->is_active);
        $this->assertSame('10.0000', $updated->{$amount});
        $this->assertTrue($service->delete($actor, $updated));
        $this->assertDatabaseMissing($kind, ['id' => $updated->id]);
    }

    #[DataProvider('definitions')]
    public function test_validation_rejects_invalid_values_codes_enums_and_dates(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        $actor = $this->admin();
        $service = app($manager);
        $base = ['name' => 'Invalid', 'code' => 'UNIQUE', $amount => '10.0000'];
        foreach (['-0.0001', '100.0001', '1.00001'] as $value) {
            $this->reject(fn () => $service->save($actor, array_replace($base, [$amount => $value])));
        }
        $this->reject(fn () => $service->save($actor, array_replace($base, ['type' => 'compound'])));
        $this->reject(fn () => $service->save($actor, array_replace($base, ['type' => 'fixed', $amount => $kind === 'taxes' ? '100000000.0000' : '10000000000000000.0000'])));
        $dates = $kind === 'taxes'
            ? ['effective_from' => '2026-10-12', 'effective_to' => '2026-10-11']
            : ['starts_at' => '2026-10-12 12:00:00', 'ends_at' => '2026-10-11 12:00:00'];
        $this->reject(fn () => $service->save($actor, array_replace($base, $dates)));
        $this->reject(fn () => $service->save($actor, array_replace($base, [$kind === 'taxes' ? 'usage' : 'scope' => 'unsupported'])));
        $existing = $service->save($actor, $base);
        $this->reject(fn () => $service->save($actor, $base));
        $this->assertSame(1, $model::count());
        $this->assertSame($existing->id, $service->save($actor, $base, $existing)->id);
        $fixed = $service->save($actor, ['name' => 'Fixed', 'code' => 'FIXED', 'type' => 'fixed', $amount => '101.1234']);
        $this->assertSame('101.1234', $fixed->{$amount});
    }

    #[DataProvider('definitions')]
    public function test_filament_crud_search_filters_and_slug_urls(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        $this->admin();
        Livewire::test($create)->fillForm(['name' => 'UI definition', 'code' => 'UI', $amount => '10.0000'])->call('create')->assertHasNoFormErrors();
        $definition = $model::where('code', 'UI')->firstOrFail();
        $other = $model::factory()->create(['name' => 'Other', 'is_active' => false]);
        Livewire::test($list)->assertCanSeeTableRecords([$definition, $other])
            ->searchTable('UI definition')->assertCanSeeTableRecords([$definition])->assertCanNotSeeTableRecords([$other]);
        Livewire::test($list)->filterTable('is_active', true)->assertCanSeeTableRecords([$definition])->assertCanNotSeeTableRecords([$other]);
        Livewire::test($view, ['record' => $definition->slug])->assertSuccessful();
        $this->get('/admin/'.$kind.'/'.$definition->slug)->assertOk();
        Livewire::test($edit, ['record' => $definition->slug])->fillForm(['name' => 'UI renamed'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('UI renamed', $definition->fresh()->name);
        Livewire::test($edit, ['record' => $definition->slug])->callAction('delete')->assertHasNoFormErrors();
        $this->assertDatabaseMissing($kind, ['id' => $definition->id]);
    }

    #[DataProvider('definitions')]
    public function test_module_deactivation_blocks_admin_urls_policies_and_open_livewire_forms(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        $actor = $this->admin();
        $definition = $model::factory()->create();
        $original = $definition->fresh()->getAttributes();
        $page = Livewire::test($edit, ['record' => $definition->slug])->fillForm(['name' => 'Forged']);
        Module::where('code', $kind)->update(['is_active' => false]);
        $this->assertFalse($actor->can('view', $definition));
        foreach (['', '/create', '/'.$definition->slug, '/'.$definition->slug.'/edit'] as $suffix) {
            $this->get('/admin/'.$kind.$suffix)->assertForbidden();
        }
        $this->reject(fn () => app($manager)->save($actor, ['name' => 'Forged'], $definition), HttpException::class);
        $this->reject(fn () => app($manager)->delete($actor, $definition), HttpException::class);
        $page->call('save')->assertForbidden();
        $this->assertSame($original, $definition->fresh()->getAttributes());
    }

    #[DataProvider('definitions')]
    public function test_location_roles_panel_entry_and_permission_revocation(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        [$actor, $location, $role] = $this->scoped([$kind.'.view', $kind.'.update']);
        $this->assertTrue($actor->canAccessPanel(Filament::getPanel('admin')));
        $definition = $model::factory()->create();
        $this->get('/admin/'.$kind)->assertOk();
        $this->get('/admin/'.$kind.'/create')->assertForbidden();
        $this->reject(fn () => app($manager)->save($actor, ['name' => 'Forged', 'code' => 'FORGED', $amount => '10']), HttpException::class);
        app(LocationContext::class)->setFor($actor, $location->id);
        $page = Livewire::test($edit, ['record' => $definition->slug])->fillForm(['name' => 'Forged']);
        $role->revokePermissionTo($kind.'.update');
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $page->call('save')->assertForbidden();
        $this->assertNotSame('Forged', $definition->fresh()->name);
        $location->update(['is_active' => false]);
        $this->assertFalse(app(DefinitionAccess::class)->allows($actor, $kind, 'view'));
        $this->assertFalse($actor->canAccessPanel(Filament::getPanel('admin')));
    }

    #[DataProvider('definitions')]
    public function test_assignments_require_both_permissions_and_never_duplicate_or_reset_unrelated_edits(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        [$actor, $location, $role] = $this->scoped(['products.view', 'products.update', 'products.assign_'.$kind]);
        $product = Product::factory()->create();
        $definition = $model::factory()->create();
        $assignments = app(ProductAssignmentManager::class);
        $this->reject(fn () => $assignments->sync($actor, $product, $kind, [$definition->id]), HttpException::class);
        Livewire::test(EditProduct::class, ['record' => $product->slug])->assertFormFieldIsHidden($kind === 'taxes' ? 'tax_ids' : 'discount_ids');
        $role->givePermissionTo($kind.'.assign');
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $assignments->sync($actor, $product, $kind, [$definition->id]);
        $assignments->sync($actor, $product, $kind, [$definition->id]);
        $this->assertSame(1, $product->{$kind}()->count());
        $this->reject(fn () => $assignments->sync($actor, $product, $kind, [$definition->id, $definition->id]));
        $this->reject(fn () => $assignments->sync($actor, $product, $kind, [999999]));
        app(ProductCatalogManager::class)->save($actor, Product::class, ['name' => 'Unrelated edit'], $product);
        $this->assertSame([$definition->id], $product->{$kind}()->pluck($kind.'.id')->all());
        $this->reject(fn () => $assignments->createDefinition($actor, $kind, ['name' => 'Forged', 'code' => 'FORGED', $amount => '5']), HttpException::class);
        Module::where('code', $kind)->update(['is_active' => false]);
        $this->reject(fn () => $assignments->sync($actor, $product, $kind, []), HttpException::class);
        $this->assertSame(1, $product->{$kind}()->count());
    }

    #[DataProvider('definitions')]
    public function test_expired_future_inactive_and_order_assignments_are_filtered_but_existing_assignments_survive(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        $actor = $this->admin();
        $this->travelTo(now()->startOfSecond());
        $product = Product::factory()->create();
        $active = $model::factory()->create();
        $inactive = $model::factory()->create(['is_active' => false]);
        $expired = $model::factory()->create([$kind === 'taxes' ? 'effective_to' : 'ends_at' => now()->subDay()]);
        $future = $model::factory()->create([$kind === 'taxes' ? 'effective_from' : 'starts_at' => now()->addDay()]);
        $boundary = $model::factory()->create([
            $kind === 'taxes' ? 'effective_from' : 'starts_at' => now(),
            $kind === 'taxes' ? 'effective_to' : 'ends_at' => now(),
        ]);
        $assignments = app(ProductAssignmentManager::class);
        $options = $assignments->options($actor, $kind, $product);
        $this->assertArrayHasKey($active->id, $options);
        $this->assertArrayHasKey($boundary->id, $options);
        foreach ([$inactive, $expired, $future] as $invalid) {
            $this->assertArrayNotHasKey($invalid->id, $options);
            $this->reject(fn () => $assignments->sync($actor, $product, $kind, [$invalid->id]));
        }
        $product->{$kind}()->attach([$inactive->id, $expired->id]);
        $assignments->sync($actor, $product, $kind, [$inactive->id, $expired->id, $boundary->id]);
        $this->assertSame(3, $product->{$kind}()->count());
        $this->assertArrayHasKey($expired->id, $assignments->options($actor, $kind, $product));
        if ($kind === 'discounts') {
            $order = Discount::factory()->create(['scope' => 'order']);
            $this->assertArrayNotHasKey($order->id, $assignments->options($actor, $kind, $product));
            $this->reject(fn () => $assignments->sync($actor, $product, $kind, [$order->id]));
        }
    }

    #[DataProvider('definitions')]
    public function test_referenced_deletion_and_financial_edits_are_blocked_without_rewriting_history(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        $actor = $this->admin();
        $definition = $model::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $product->{$kind}()->attach($definition);
        $service = app($manager);
        $this->assertFalse($actor->can('delete', $definition));
        $this->reject(fn () => $service->delete($actor, $definition));
        $this->assertSame(1, $product->{$kind}()->count());
        $service->save($actor, ['is_active' => false], $definition);
        try {
            DB::table('definition_history_test')->insert([$kind === 'taxes' ? 'tax_id' : 'discount_id' => $definition->id, 'total' => '123.4500']);
            $before = DB::table('definition_history_test')->get()->toJson();
            $this->reject(fn () => $service->save($actor, [$amount => '99.0000'], $definition));
            $service->save($actor, ['name' => 'Historical definition', 'is_active' => false], $definition);
            $product->{$kind}()->detach($definition);
            $this->reject(fn () => $service->delete($actor, $definition));
            $this->assertSame($before, DB::table('definition_history_test')->get()->toJson());
        } finally {
            DB::table('definition_history_test')->delete();
        }
    }

    public function test_assigned_discount_cannot_be_converted_to_order_scope(): void
    {
        $actor = $this->admin();
        $discount = Discount::factory()->create(['scope' => 'product']);
        $product = Product::factory()->create();
        $product->discounts()->attach($discount);
        $this->reject(fn () => app(DiscountManager::class)->save($actor, ['scope' => 'order'], $discount));
        $this->assertSame('product', $discount->fresh()->scope);
        $this->assertSame([$discount->id], $product->discounts()->pluck('discounts.id')->all());
    }

    #[DataProvider('definitions')]
    public function test_real_sales_references_protect_financial_settings_and_product_links(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        $actor = $this->admin();
        $this->artisan('migrate', ['--force' => true, '--path' => [
            'app/Modules/Sales/Database/Migrations/2026_10_11_000001_create_sales_orders_table.php',
            'app/Modules/Sales/Database/Migrations/2026_10_11_000002_create_sales_order_items_table.php',
            'app/Modules/Sales/Database/Migrations/2026_10_12_075122_add_public_identifiers_to_sales_tables.php',
        ]])->assertSuccessful();
        $definition = $model::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        app(ProductAssignmentManager::class)->sync($actor, $product, $kind, [$definition->id]);
        $item = SalesOrderItem::factory()->create(['variant_id' => $variant->id, 'discount_amount' => '2.0000', 'tax_amount' => '3.0000', 'line_total' => '51.0000']);
        $before = $item->fresh()->getAttributes();
        $this->reject(fn () => app($manager)->save($actor, [$amount => '99.0000'], $definition));
        $this->reject(fn () => app(ProductAssignmentManager::class)->sync($actor, $product, $kind, []));
        app($manager)->save($actor, ['is_active' => false], $definition);
        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertSame([$definition->id], $product->{$kind}()->pluck($kind.'.id')->all());
    }

    #[DataProvider('definitions')]
    public function test_inactive_users_and_removed_location_membership_block_services(string $kind, string $model, string $manager, string $create, string $edit, string $view, string $list, string $amount): void
    {
        [$actor, $location] = $this->scoped([$kind.'.view', $kind.'.update', $kind.'.delete']);
        $definition = $model::factory()->create();
        $actor->locations()->detach($location);
        $this->reject(fn () => app($manager)->save($actor, ['name' => 'Forged'], $definition), HttpException::class);
        $this->reject(fn () => app($manager)->delete($actor, $definition), HttpException::class);
        $actor->locations()->attach($location);
        $actor->update(['is_active' => false]);
        $this->reject(fn () => app($manager)->save($actor, ['name' => 'Forged'], $definition), HttpException::class);
        $this->assertDatabaseHas($kind, ['id' => $definition->id, 'name' => $definition->name]);
    }

    public function test_registration_is_idempotent_preserves_grants_activation_and_existing_definitions(): void
    {
        $tax = Tax::factory()->create(['rate' => '7.2500']);
        $discount = Discount::factory()->create(['value' => '12.3400']);
        [$actor, $location, $role] = $this->scoped(['taxes.view']);
        $before = [$tax->fresh()->getAttributes(), $discount->fresh()->getAttributes()];
        Module::whereIn('code', ['taxes', 'discounts'])->update(['is_active' => false]);
        $historical = Permission::create(['name' => 'legacy.definitions_export', 'guard_name' => 'web']);
        $role->givePermissionTo($historical);
        $this->artisan('modules:sync-permissions')->assertSuccessful();
        $this->artisan('modules:sync-permissions')->assertSuccessful();
        $this->assertSame($location->id, getPermissionsTeamId());
        $this->assertTrue($role->hasPermissionTo($historical));
        $this->assertSame(10, Permission::whereIn('name', DefinitionPermissions::all())->count());
        $this->assertSame(0, Module::whereIn('code', ['taxes', 'discounts'])->where('is_active', true)->count());
        $this->assertSame($before, [$tax->fresh()->getAttributes(), $discount->fresh()->getAttributes()]);
        $this->assertSame(1, Tax::count());
        $this->assertSame(1, Discount::count());
    }

    public function test_inline_product_creation_rejects_order_discounts_and_financial_validation_bypasses(): void
    {
        $actor = $this->admin();
        $manager = app(ProductAssignmentManager::class);
        $this->reject(fn () => $manager->createDefinition($actor, 'discounts', ['name' => 'Order', 'scope' => 'order', 'value' => '10']));
        Livewire::test(CreateProduct::class)->callAction(TestAction::make('createOption')->schemaComponent('tax_ids'), [
            'name' => 'Bad percentage', 'code' => 'BAD', 'rate' => '101',
        ])->assertHasFormErrors(['rate' => 'max']);
        $this->assertDatabaseMissing('taxes', ['code' => 'BAD']);
    }

    public function test_migrations_are_discovered_in_global_filename_order_without_renaming(): void
    {
        $files = app('migrator')->getMigrationFiles(app('migrator')->paths());
        $tax = array_search('2026_10_10_000001_create_taxes_table', array_keys($files), true);
        $taxPivot = array_search('2026_10_10_000002_create_product_taxes_table', array_keys($files), true);
        $discount = array_search('2026_10_10_000003_create_discounts_table', array_keys($files), true);
        $discountPivot = array_search('2026_10_10_000004_create_discount_products_table', array_keys($files), true);
        $this->assertIsInt($tax);
        $this->assertLessThan($taxPivot, $tax);
        $this->assertLessThan($discountPivot, $discount);
        $this->assertStringContainsString('Discounts', $files['2026_10_10_000001_create_taxes_table']);
        $this->assertStringContainsString('Taxes', $files['2026_10_10_000003_create_discounts_table']);
        $this->assertTrue(Schema::hasColumns('taxes', ['uuid', 'slug']));
        $this->assertTrue(Schema::hasColumns('discounts', ['uuid', 'slug']));
        $this->assertFalse(Schema::hasColumn('product_taxes', 'slug'));
        $this->assertFalse(Schema::hasColumn('discount_products', 'uuid'));
    }
}
