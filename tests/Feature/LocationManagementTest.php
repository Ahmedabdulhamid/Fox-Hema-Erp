<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Filament\Resources\Locations\Pages\EditLocation;
use App\Modules\AccessManagement\Filament\Resources\Locations\Pages\ListLocations;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Policies\LocationPolicy;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\StockTransfer\Models\StockTransfer;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class LocationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        $this->admin = $this->provisionTestSuperAdmin();
        $this->admin->update(['must_change_password' => false]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_delete_requires_its_own_permission(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('isGlobalSuperAdmin')->andReturnFalse();
        $actor->shouldReceive('can')->once()->with('locations.delete')->andReturnFalse();

        $this->assertFalse((new LocationPolicy)->delete($actor, $this->location()));
    }

    public function test_delete_permission_is_independent_of_update_permission_and_requires_membership(): void
    {
        $location = $this->location();
        $actor = User::factory()->create();
        $actor->locations()->attach($location);
        app(LocationContext::class)->setFor($actor, $location->id);
        $actor->givePermissionTo('locations.delete');
        $record = Mockery::mock(Location::class)->makePartial();
        $record->setRawAttributes($location->getAttributes(), true);
        $record->exists = true;
        $record->shouldReceive('hasDependencies')->andReturnFalse();

        $this->assertFalse($actor->can('locations.update'));
        $this->assertTrue((new LocationPolicy)->delete($actor, $record));
        $actor->locations()->detach($location);
        $this->assertFalse((new LocationPolicy)->delete($actor, $record));
    }

    public function test_unused_location_can_be_deleted_through_the_table_action(): void
    {
        $location = $this->location();
        Livewire::test(ListLocations::class)->callAction(TestAction::make('delete')->table($location));

        $this->assertModelMissing($location);
    }

    /** @return array<string, array{class-string, string}> */
    public static function dependencies(): array
    {
        return [
            'purchase order' => [PurchaseOrder::class, 'location_id'],
            'purchase receipt' => [PurchaseReceipt::class, 'location_id'],
            'sales order' => [SalesOrder::class, 'location_id'],
            'device' => [PosDevice::class, 'location_id'],
            'session' => [PosSession::class, 'location_id'],
            'transaction' => [PosTransaction::class, 'location_id'],
            'size allocation' => [SizeAllocation::class, 'location_id'],
            'outgoing transfer' => [StockTransfer::class, 'from_location_id'],
            'incoming transfer' => [StockTransfer::class, 'to_location_id'],
        ];
    }

    #[DataProvider('dependencies')]
    public function test_operational_dependencies_block_deletion_and_type_changes(string $model, string $column): void
    {
        $location = $this->location();
        $attributes = [$column => $location->id];
        if (in_array($model, [PosSession::class, PosTransaction::class], true)) {
            $device = PosDevice::factory()->create(['location_id' => $location->id]);
            $attributes['pos_device_id'] = $device->id;
            if ($model === PosTransaction::class) {
                $attributes['pos_session_id'] = PosSession::factory()->create([
                    'location_id' => $location->id, 'pos_device_id' => $device->id,
                ])->id;
            }
        }
        $model::factory()->create($attributes);

        $this->assertProtected($location);
    }

    public function test_zero_stock_balance_still_protects_location(): void
    {
        $location = $this->location();
        StockBalance::create(['location_id' => $location->id, 'variant_id' => ProductVariant::factory()->create()->id,
            'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'quantity_available' => 0]);

        $this->assertProtected($location);
    }

    public function test_soft_deleted_user_membership_still_protects_location(): void
    {
        $location = $this->location();
        $user = User::factory()->create();
        $user->locations()->attach($location);
        $user->delete();

        $this->assertProtected($location);
    }

    public function test_direct_permission_assignment_protects_location_without_membership(): void
    {
        $location = $this->location();
        $user = User::factory()->create();
        DB::table('model_has_permissions')->insert(['location_id' => $location->id, 'model_id' => $user->id,
            'model_type' => $user->getMorphClass(), 'permission_id' => Permission::where('name', 'locations.view')->value('id')]);

        $this->assertProtected($location);
    }

    public function test_role_and_audit_dependencies_protect_locations(): void
    {
        $roleLocation = $this->location();
        Role::create(['location_id' => $roleLocation->id, 'name' => 'local-manager', 'guard_name' => 'web']);
        $this->assertProtected($roleLocation);
        $auditLocation = $this->location();
        DB::table('auth_events')->insert(['location_id' => $auditLocation->id, 'event' => 'location_reviewed', 'occurred_at' => now()]);
        $this->assertProtected($auditLocation);
    }

    public function test_number_sequence_without_documents_protects_location(): void
    {
        $location = $this->location();
        DB::table('document_number_sequences')->insert(['location_id' => $location->id, 'document_type' => 'SO',
            'location_code' => $location->code, 'last_number' => 1]);

        $this->assertProtected($location);
    }

    public function test_used_type_is_disabled_and_forged_form_state_does_not_change_it(): void
    {
        $location = $this->location();
        SalesOrder::factory()->create(['location_id' => $location->id]);
        Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->assertFormFieldIsDisabled('type')->assertActionHidden('delete')
            ->fillForm(['type' => 'warehouse', 'name' => 'Updated name'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('branch', $location->fresh()->type);
        $this->assertSame('Updated name', $location->fresh()->name);
    }

    public function test_inactive_sales_history_remains_visible_to_global_admin_while_operations_are_denied(): void
    {
        Module::where('code', 'sales')->update(['is_active' => true]);
        $location = $this->location();
        $order = SalesOrder::factory()->create(['location_id' => $location->id]);
        $delivery = $this->insertHistoricalDocument(SalesDelivery::class, ['sales_order_id' => $order->id, 'location_id' => $location->id]);
        $invoice = $this->insertHistoricalDocument(SalesInvoice::class, ['sales_order_id' => $order->id, 'location_id' => $location->id]);
        $return = $this->insertHistoricalDocument(SalesReturn::class, ['sales_order_id' => $order->id, 'location_id' => $location->id]);
        $credit = $this->insertHistoricalDocument(SalesCreditNote::class, ['sales_invoice_id' => $invoice->id, 'location_id' => $location->id]);
        $location->update(['is_active' => false]);
        $access = app(SalesAccess::class);

        foreach ([$order, $delivery, $invoice, $return, $credit] as $document) {
            $this->assertTrue(Gate::allows('view', $document), $document::class);
            $this->assertFalse(Gate::allows('update', $document), $document::class);
        }
        $this->assertTrue($access->scopeOrders(SalesOrder::query(), $this->admin)->whereKey($order)->exists());
        $this->assertTrue($access->scopeDeliveries(SalesDelivery::query(), $this->admin)->whereKey($delivery)->exists());
        $this->assertTrue($access->scopeInvoices(SalesInvoice::query(), $this->admin)->whereKey($invoice)->exists());
        $this->assertTrue($access->scopeReturns(SalesReturn::query(), $this->admin)->whereKey($return)->exists());
        $this->assertTrue(app(SalesCreditNoteService::class)->scope(SalesCreditNote::query(), $this->admin)->whereKey($credit)->exists());
        $this->assertFalse($access->branches($this->admin)->whereKey($location)->exists());
        $readPermissions = [SalesPermissions::VIEW, SalesPermissions::DELIVERIES_VIEW, SalesPermissions::INVOICES_VIEW,
            SalesPermissions::RETURNS_VIEW, SalesPermissions::CREDITS_VIEW, SalesPermissions::INVOICES_DOWNLOAD, SalesPermissions::CREDITS_DOWNLOAD];
        foreach ($readPermissions as $permission) {
            $this->assertTrue($access->allows($this->admin, $permission, $location->id), $permission);
        }
        foreach (array_diff(SalesPermissions::all(), $readPermissions) as $permission) {
            $this->assertFalse($access->allows($this->admin, $permission, $location->id), $permission);
        }
        $this->assertProtected($location);
    }

    public function test_scoped_users_cannot_read_foreign_or_inactive_sales_history(): void
    {
        Module::where('code', 'sales')->update(['is_active' => true]);
        $own = $this->location();
        $foreign = $this->location();
        $actor = User::factory()->create(['is_active' => true]);
        $actor->locations()->attach($own);
        app(LocationContext::class)->setFor($actor, $own->id);
        $actor->givePermissionTo(SalesPermissions::VIEW);
        $ownOrder = SalesOrder::factory()->create(['location_id' => $own->id]);
        $foreignOrder = SalesOrder::factory()->create(['location_id' => $foreign->id]);
        $access = app(SalesAccess::class);

        $this->assertTrue($access->scopeOrders(SalesOrder::query(), $actor)->whereKey($ownOrder)->exists());
        $this->assertFalse($access->scopeOrders(SalesOrder::query(), $actor)->whereKey($foreignOrder)->exists());
        $this->assertFalse($access->allows($actor, SalesPermissions::VIEW, $foreign->id));
        $own->update(['is_active' => false]);
        $this->assertFalse($access->scopeOrders(SalesOrder::query(), $actor)->exists());
        $this->expectException(AuthorizationException::class);
        app(LocationContext::class)->setFor($actor, $own->id);
    }

    public function test_unused_location_type_can_be_changed(): void
    {
        $location = $this->location();
        $location->update(['type' => 'warehouse']);

        $this->assertSame('warehouse', $location->fresh()->type);
    }

    public function test_suppressing_model_events_cannot_bypass_dependency_protection(): void
    {
        $location = $this->location();
        SalesOrder::factory()->create(['location_id' => $location->id]);
        try {
            $location->deleteQuietly();
            $this->fail('Quiet deletion bypassed location protection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('record', $exception->errors());
        }
        try {
            $location->type = 'warehouse';
            $location->saveQuietly();
            $this->fail('Quiet save bypassed location type protection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.type', $exception->errors());
        }
        $this->assertSame('branch', $location->fresh()->type);
    }

    private function location(): Location
    {
        return Location::create(['name' => fake()->unique()->city(), 'type' => 'branch', 'is_active' => true]);
    }

    private function assertProtected(Location $location): void
    {
        $this->assertTrue($location->hasDependencies());
        $this->assertFalse(Gate::allows('delete', $location));
        try {
            $location->delete();
            $this->fail('Dependent location was deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('record', $exception->errors());
        }
        try {
            $location->update(['type' => 'warehouse']);
            $this->fail('Used location type was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.type', $exception->errors());
        }
        $this->assertSame('branch', $location->fresh()->type);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    private function insertHistoricalDocument(string $model, array $attributes): Model
    {
        $table = (new $model)->getTable();
        $id = DB::table($table)->insertGetId([...$model::factory()->raw($attributes), 'uuid' => (string) Str::uuid7()]);

        return $model::findOrFail($id);
    }
}
