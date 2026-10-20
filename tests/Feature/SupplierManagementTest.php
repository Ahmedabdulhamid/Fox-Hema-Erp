<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\PermissionGroups;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Modules\Purchasing\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\SupplierService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\PurchasingTestSetup;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use PurchasingTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchasingDatabase();
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'purchasing')->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function scoped(array $permissions): array
    {
        $location = Location::create(['name' => 'Supplier team branch', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($location);
        app(LocationContext::class)->setFor($actor, $location->id);
        $role = Role::create(['name' => 'supplier-manager', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo($permissions);
        $actor->assignRole($role);
        $this->actingAs($actor);

        return [$actor, $location, $role];
    }

    private function admin(): User
    {
        $actor = $this->provisionTestSuperAdmin();
        $actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, null);

        return $actor;
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught);

            return;
        }
        $this->fail('Expected operation to be rejected.');
    }

    public function test_service_creates_updates_and_explicitly_changes_status(): void
    {
        [$actor] = $this->scoped(PurchasingPermissions::all());
        $service = app(SupplierService::class);
        $supplier = $service->create($actor, [
            'code' => ' EG-001 ', 'name' => ' Nile Supply ', 'contact' => 'Mona', 'phone' => '+20 10 5550 0101',
            'email' => 'nile@example.test', 'address' => 'Cairo', 'tax_number' => 'DEMO-TAX',
            'payment_terms_days' => '30', 'notes' => 'Test supplier', 'uuid' => 'forged', 'slug' => 'forged',
        ]);
        $this->assertSame('EG-001', $supplier->code);
        $this->assertSame('Nile Supply', $supplier->name);
        $this->assertSame(30, $supplier->payment_terms_days);
        $this->assertTrue($supplier->is_active);
        $this->assertTrue(Str::isUuid($supplier->uuid));
        $this->assertNotSame('forged', $supplier->slug);
        $identifiers = [$supplier->id, $supplier->uuid, $supplier->slug];
        $supplier = $service->update($actor, $supplier, ['name' => 'Updated supplier', 'email' => null, 'payment_terms_days' => 65535]);
        $this->assertSame('Updated supplier', $supplier->name);
        $this->assertNull($supplier->email);
        $this->assertSame(65535, $supplier->payment_terms_days);
        $this->assertSame($identifiers, [$supplier->id, $supplier->uuid, $supplier->slug]);
        $supplier = $service->deactivate($actor, $supplier);
        $this->assertFalse($supplier->is_active);
        $this->assertArrayNotHasKey($supplier->id, $service->activeOptions($actor));
        $supplier = $service->activate($actor, $supplier);
        $this->assertTrue($supplier->is_active);
        $this->assertArrayHasKey($supplier->id, $service->activeOptions($actor));
    }

    public function test_validation_enforces_schema_lengths_email_integer_terms_and_unique_codes(): void
    {
        $actor = $this->admin();
        $service = app(SupplierService::class);
        $base = ['code' => 'VALID', 'name' => 'Valid supplier'];
        foreach ([
            ['name' => ' '], ['code' => ' '], ['code' => str_repeat('C', 51)],
            ['name' => str_repeat('N', 256)], ['contact' => str_repeat('C', 256)],
            ['phone' => str_repeat('1', 31)], ['email' => 'invalid'], ['email' => str_repeat('e', 256)],
            ['tax_number' => str_repeat('T', 101)], ['payment_terms_days' => -1],
            ['payment_terms_days' => '1.5'], ['payment_terms_days' => 65536],
            ['address' => str_repeat('م', 32768)], ['notes' => str_repeat('n', 65536)], ['is_active' => false],
        ] as $invalid) {
            $this->reject(fn () => $service->create($actor, array_replace($base, $invalid)));
        }
        $supplier = $service->create($actor, $base);
        $this->assertSame(0, $supplier->payment_terms_days);
        $this->reject(fn () => $service->create($actor, $base));
        $other = Supplier::factory()->create();
        $this->reject(fn () => $service->update($actor, $other, ['code' => $supplier->code]));
        $this->assertSame($supplier->id, $service->update($actor, $supplier, $base)->id);
    }

    public function test_status_permission_is_independent_and_cannot_be_bypassed_by_update(): void
    {
        [$actor] = $this->scoped([PurchasingPermissions::VIEW, PurchasingPermissions::CREATE, PurchasingPermissions::UPDATE]);
        $service = app(SupplierService::class);
        $supplier = Supplier::factory()->create();
        $this->reject(fn () => $service->deactivate($actor, $supplier), HttpException::class);
        $this->reject(fn () => $service->activate($actor, $supplier), HttpException::class);
        $this->reject(fn () => $service->update($actor, $supplier, ['is_active' => false]));
        $this->assertTrue($supplier->fresh()->is_active);
        [$statusActor] = $this->scoped([PurchasingPermissions::VIEW, PurchasingPermissions::DEACTIVATE]);
        $this->assertFalse($service->deactivate($statusActor, $supplier)->is_active);
        $this->reject(fn () => $service->update($statusActor, $supplier, ['name' => 'Forged']), HttpException::class);
    }

    public function test_inactive_suppliers_keep_historical_relationships_and_stay_visible(): void
    {
        $actor = $this->admin();
        $supplier = Supplier::factory()->create();
        $order = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id]);
        $bill = VendorBill::factory()->create(['supplier_id' => $supplier->id, 'purchase_order_id' => $order->id]);
        $payment = VendorPayment::factory()->create(['supplier_id' => $supplier->id]);
        $before = [$order->fresh()->getAttributes(), $bill->fresh()->getAttributes(), $payment->fresh()->getAttributes()];
        app(SupplierService::class)->deactivate($actor, $supplier);
        $this->assertFalse($order->fresh()->supplier->is_active);
        $this->assertTrue($bill->fresh()->supplier->is($supplier));
        $this->assertTrue($payment->fresh()->supplier->is($supplier));
        $this->assertSame($before, [$order->fresh()->getAttributes(), $bill->fresh()->getAttributes(), $payment->fresh()->getAttributes()]);
        Livewire::test(ListSuppliers::class)->assertCanSeeTableRecords([$supplier]);
        $this->assertSame([], SupplierResource::getRelations());
    }

    public function test_unauthorized_operations_and_invalid_team_context_are_denied(): void
    {
        [$actor, $location] = $this->scoped([PurchasingPermissions::VIEW]);
        $supplier = Supplier::factory()->create();
        $service = app(SupplierService::class);
        $this->reject(fn () => $service->create($actor, ['code' => 'FORGED', 'name' => 'Forged']), HttpException::class);
        $this->reject(fn () => $service->update($actor, $supplier, ['name' => 'Forged']), HttpException::class);
        $actor->locations()->detach($location);
        $this->assertFalse(app(PurchasingAccess::class)->allows($actor, PurchasingPermissions::VIEW));
        $actor->locations()->attach($location);
        $location->update(['is_active' => false]);
        $this->assertFalse(app(PurchasingAccess::class)->allows($actor, PurchasingPermissions::VIEW));
        $location->update(['is_active' => true]);
        $actor->update(['is_active' => false]);
        $this->assertFalse(app(PurchasingAccess::class)->allows($actor, PurchasingPermissions::VIEW));
        $this->assertFalse(app(PurchasingAccess::class)->allows(null, PurchasingPermissions::VIEW));
        $this->assertDatabaseMissing('suppliers', ['code' => 'FORGED']);
    }

    public function test_shared_suppliers_use_current_location_permissions_and_panel_admission(): void
    {
        [$actor, $first] = $this->scoped([PurchasingPermissions::VIEW]);
        $supplier = Supplier::factory()->create();
        $second = Location::create(['name' => 'Other branch', 'type' => 'branch']);
        $actor->locations()->attach($second);
        $this->assertTrue($actor->canAccessPanel(Filament::getPanel('admin')));
        Livewire::test(ListSuppliers::class)->assertCanSeeTableRecords([$supplier]);
        app(LocationContext::class)->setFor($actor, $second->id);
        $this->assertFalse(SupplierResource::canViewAny());
        $this->assertSame(0, SupplierResource::getEloquentQuery()->count());
        app(LocationContext::class)->setFor($actor, $first->id);
        $this->get(SupplierResource::getUrl('view', ['record' => $supplier]))->assertOk();
    }

    public function test_super_admin_has_access_without_team_but_disabled_module_blocks_all_operations(): void
    {
        $actor = $this->admin();
        $service = app(SupplierService::class);
        $supplier = $service->create($actor, ['code' => 'ADMIN', 'name' => 'Admin supplier']);
        $this->assertTrue($actor->can('update', $supplier));
        $this->assertFalse($actor->can('delete', $supplier));
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        foreach ([
            fn () => $service->create($actor, ['code' => 'DENIED', 'name' => 'Denied']),
            fn () => $service->update($actor, $supplier, ['name' => 'Denied']),
            fn () => $service->activate($actor, $supplier),
            fn () => $service->deactivate($actor, $supplier),
        ] as $operation) {
            $this->reject($operation, HttpException::class);
        }
        $this->assertFalse(SupplierResource::canViewAny());
        $this->get(SupplierResource::getUrl())->assertForbidden();
    }

    public function test_filament_creates_edits_views_filters_and_performs_explicit_status_actions(): void
    {
        $this->scoped(PurchasingPermissions::all());
        Livewire::test(CreateSupplier::class)->fillForm([
            'code' => 'UI-001', 'name' => 'UI supplier', 'email' => 'ui@example.test', 'payment_terms_days' => 15,
        ])->call('create')->assertHasNoFormErrors();
        $supplier = Supplier::where('code', 'UI-001')->firstOrFail();
        Livewire::test(EditSupplier::class, ['record' => $supplier->slug])->fillForm(['name' => 'Edited supplier'])
            ->call('save')->assertHasNoFormErrors()->assertActionDoesNotExist('delete');
        $this->assertSame('Edited supplier', $supplier->fresh()->name);
        Livewire::test(ViewSupplier::class, ['record' => $supplier->slug])->assertOk()->callAction('deactivate');
        $this->assertFalse($supplier->fresh()->is_active);
        Livewire::test(ListSuppliers::class)->filterTable('is_active', false)->assertCanSeeTableRecords([$supplier])
            ->callAction(TestAction::make('activate')->table($supplier));
        $this->assertTrue($supplier->fresh()->is_active);
        Livewire::test(ListSuppliers::class)->searchTable('UI-001')->assertCanSeeTableRecords([$supplier])
            ->assertActionDoesNotExist(TestAction::make('delete')->table($supplier))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
        $this->assertFalse(SupplierResource::canDelete($supplier));
    }

    public function test_direct_page_access_and_forged_livewire_requests_are_blocked(): void
    {
        [$actor, $location, $role] = $this->scoped([PurchasingPermissions::VIEW, PurchasingPermissions::UPDATE]);
        $supplier = Supplier::factory()->create();
        $page = Livewire::test(EditSupplier::class, ['record' => $supplier->slug])->fillForm(['name' => 'Forged']);
        $role->revokePermissionTo(PurchasingPermissions::UPDATE);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $page->call('save')->assertForbidden();
        $this->get(SupplierResource::getUrl('create'))->assertForbidden();
        $this->get(SupplierResource::getUrl('edit', ['record' => $supplier]))->assertForbidden();
        app(LocationContext::class)->setFor($actor, $location->id);
        Livewire::test(ViewSupplier::class, ['record' => $supplier->slug])->assertActionHidden('deactivate')
            ->call('mountAction', 'deactivate')->call('callMountedAction');
        $this->assertTrue($supplier->fresh()->is_active);
        $this->assertSame($supplier->name, $supplier->fresh()->name);
    }

    public function test_disabled_module_blocks_a_livewire_page_already_open(): void
    {
        $this->admin();
        $supplier = Supplier::factory()->create();
        $page = Livewire::test(EditSupplier::class, ['record' => $supplier->slug])->fillForm(['name' => 'Forged']);
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        $page->call('save')->assertNotFound();
        $this->assertSame($supplier->name, $supplier->fresh()->name);
    }

    public function test_forged_status_field_does_not_change_status_during_filament_edit(): void
    {
        $this->scoped([PurchasingPermissions::VIEW, PurchasingPermissions::UPDATE]);
        $supplier = Supplier::factory()->create();
        Livewire::test(EditSupplier::class, ['record' => $supplier->slug])
            ->set('data.is_active', false)->fillForm(['name' => 'Allowed edit'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue($supplier->fresh()->is_active);
        $this->assertSame('Allowed edit', $supplier->fresh()->name);
    }

    public function test_registration_maps_implemented_purchasing_permissions_and_preserves_existing_grants(): void
    {
        [$actor, $location, $role] = $this->scoped([PurchasingPermissions::VIEW]);
        $before = $role->permissions()->pluck('name')->all();
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        app(ModuleRegistry::class)->register();
        app(ModuleRegistry::class)->register();
        $this->assertSame($location->id, getPermissionsTeamId());
        $this->assertSame($before, $role->fresh()->permissions()->pluck('name')->all());
        $module = Module::where('code', 'purchasing')->firstOrFail();
        $this->assertFalse($module->is_active);
        $this->assertEqualsCanonicalizing(PurchasingPermissions::all(), $module->permissions()->pluck('name')->all());
        $this->assertSame(count(PurchasingPermissions::all()), Permission::where('name', 'like', 'purchasing.%')->count());
        $this->assertArrayHasKey('Purchasing', app(PermissionGroups::class)->options($actor));

    }

    public function test_edit_status_actions_refresh_status_without_discarding_unsaved_details(): void
    {
        $this->admin();
        $supplier = Supplier::factory()->create();
        Livewire::test(EditSupplier::class, ['record' => $supplier->slug])
            ->fillForm(['notes' => 'Unsaved detail'])
            ->callAction('deactivate')->assertFormSet(['is_active' => false, 'notes' => 'Unsaved detail'])
            ->callAction('activate')->assertFormSet(['is_active' => true, 'notes' => 'Unsaved detail']);
        $this->assertTrue($supplier->fresh()->is_active);
        $this->assertNotSame('Unsaved detail', $supplier->fresh()->notes);
    }
}
