<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Filament\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Database\Seeders\PosBranchClearingAccountSeeder;
use App\Modules\POS\Database\Seeders\PosDatabaseSeeder;
use App\Modules\POS\Database\Seeders\PosSessionSeeder;
use App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\Pages\CreatePosDevicePaymentMethod;
use App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\Pages\EditPosDevicePaymentMethod;
use App\Modules\POS\Filament\Resources\PosDevices\Pages\CreatePosDevice;
use App\Modules\POS\Filament\Resources\PosDevices\Pages\EditPosDevice;
use App\Modules\POS\Filament\Resources\PosDevices\PosDeviceResource;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosDevicePaymentMethod;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosReadModels;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PosDeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['pos', 'payments', 'treasury'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'POS branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo(PosPermissions::all());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
    }

    public function test_device_can_be_created_and_renamed_without_entering_a_code(): void
    {
        $this->travelTo('2026-11-02 12:00:00');

        Livewire::test(CreatePosDevice::class)->assertFormFieldDoesNotExist('device_code')
            ->fillForm(['name' => 'Front counter', 'location_id' => $this->branch->id])
            ->call('create')->assertHasNoFormErrors();

        $device = PosDevice::firstOrFail();
        $this->assertSame($this->branch->code.'-POS-2026-000001', $device->device_code);
        $this->assertSame($this->actor->id, $device->registered_by);
        Livewire::test(EditPosDevice::class, ['record' => $device->getRouteKey()])->assertFormFieldDoesNotExist('device_code')
            ->fillForm(['name' => 'Renamed counter'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($device->device_code, $device->fresh()->device_code);
        $this->assertDatabaseHas('pos_devices', ['id' => $device->id, 'name' => 'Renamed counter']);
    }

    public function test_generated_view_and_edit_links_resolve_the_device_uuid(): void
    {
        $this->actor->givePermissionTo('locations.view');
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $foreignDevice = PosDevice::factory()->create();
        $this->withSession([LocationContext::SESSION_KEY => $this->branch->id]);

        $this->assertSame($device->uuid, $device->getRouteKey());
        $this->get(PosDeviceResource::getUrl('view', ['record' => $device]))->assertOk()->assertSee($device->device_code);
        $this->get(PosDeviceResource::getUrl('edit', ['record' => $device]))->assertOk()->assertSee($device->name);
        $this->get(PosDeviceResource::getUrl('view', ['record' => $foreignDevice]))->assertNotFound();
        $this->get(PosDeviceResource::getUrl('edit', ['record' => $foreignDevice]))->assertNotFound();
    }

    public function test_observer_generates_codes_and_rolls_back_numbering_with_failed_creation(): void
    {
        $this->travelTo('2026-11-02 12:00:00');
        $first = PosDevice::factory()->create(['location_id' => $this->branch->id, 'device_code' => 'MANUAL']);
        try {
            DB::transaction(function (): void {
                PosDevice::factory()->create(['location_id' => $this->branch->id]);
                throw new RuntimeException('Abort device creation');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort device creation', $exception->getMessage());
        }

        $second = PosDevice::factory()->create(['location_id' => $this->branch->id]);

        $this->assertSame($this->branch->code.'-POS-2026-000001', $first->device_code);
        $this->assertSame($this->branch->code.'-POS-2026-000002', $second->device_code);
        $this->assertDatabaseCount('pos_devices', 2);
        $this->assertSame(2, DB::table('document_numbers')->where('document_type', 'POS')->count());
    }

    public function test_generated_code_cannot_be_changed(): void
    {
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        try {
            $device->update(['device_code' => 'MANUAL']);
            $this->fail('Generated device code was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('device_code', $exception->errors());
        }
        $this->assertDatabaseMissing('pos_devices', ['device_code' => 'MANUAL']);
    }

    public function test_new_vodafone_wallet_method_is_pos_selectable_without_treasury_account(): void
    {
        $this->actor->givePermissionTo(PaymentsPermissions::all());
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);

        Livewire::test(CreatePaymentMethod::class)
            ->assertFormFieldExists('available_in_pos')
            ->fillForm([
                'name' => 'Vodafone Cash',
                'type' => 'wallet',
                'is_active' => true,
                'requires_reference' => true,
                'sort_order' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $method = PaymentMethod::where('name', 'Vodafone Cash')->firstOrFail();
        $this->assertTrue($method->available_in_pos);
        $this->assertSame(
            [$method->id => 'Vodafone Cash'],
            app(PosReadModels::class)->paymentMethodOptions($this->actor, (int) $device->id),
        );

        $this->assertSame([], app(PosReadModels::class)->treasuryAccountOptions($this->actor, (int) $device->id, (int) $method->id));

        Livewire::test(CreatePosDevicePaymentMethod::class)
            ->fillForm([
                'pos_device_id' => $device->id,
                'payment_methods' => [[
                    'payment_method_id' => $method->id,
                    'treasury_account_id' => null,
                    'is_active' => true,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $configuration = PosDevicePaymentMethod::where('pos_device_id', $device->id)->firstOrFail();
        $editPage = Livewire::test(EditPosDevicePaymentMethod::class, ['record' => $configuration->getRouteKey()])
            ->assertFormFieldIsDisabled('pos_device_id')
            ->assertFormFieldExists('payment_methods');
        $this->assertSame(
            __('pos.edit_device_payment_methods_title', ['device' => $device->name]),
            $editPage->instance()->getTitle(),
        );
    }

    public function test_edit_saves_cash_and_vodafone_payment_methods_together(): void
    {
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $cash = PaymentMethod::factory()->create([
            'type' => 'cash',
            'available_in_pos' => true,
            'is_active' => true,
        ]);
        $wallet = PaymentMethod::factory()->create([
            'name' => 'Vodafone Cash',
            'type' => 'wallet',
            'available_in_pos' => true,
            'is_active' => true,
        ]);
        $cashbox = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        $configuration = app(PosDeviceManagement::class)->configurePaymentMethods($this->actor, $device, [
            ['payment_method_id' => $cash->id, 'treasury_account_id' => $cashbox->id, 'is_active' => true],
            ['payment_method_id' => $wallet->id, 'treasury_account_id' => null, 'is_active' => true],
        ]);

        Livewire::test(EditPosDevicePaymentMethod::class, ['record' => $configuration->getRouteKey()])
            ->fillForm(['payment_methods' => [
                ['payment_method_id' => $cash->id, 'treasury_account_id' => $cashbox->id, 'is_active' => true],
                ['payment_method_id' => $wallet->id, 'treasury_account_id' => null, 'is_active' => true],
            ]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('pos_device_payment_methods', [
            'pos_device_id' => $device->id,
            'payment_method_id' => $cash->id,
            'treasury_account_id' => $cashbox->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('pos_device_payment_methods', [
            'pos_device_id' => $device->id,
            'payment_method_id' => $wallet->id,
            'treasury_account_id' => null,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('pos_device_payment_methods', 2);
    }

    public function test_edit_displays_the_active_shift_blocker_on_the_device_field(): void
    {
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $cash = PaymentMethod::factory()->create([
            'type' => 'cash',
            'available_in_pos' => true,
            'is_active' => true,
        ]);
        $wallet = PaymentMethod::factory()->create([
            'name' => 'Vodafone Cash',
            'type' => 'wallet',
            'available_in_pos' => true,
            'is_active' => true,
        ]);
        $cashbox = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        $configuration = app(PosDeviceManagement::class)->configurePaymentMethods($this->actor, $device, [
            ['payment_method_id' => $cash->id, 'treasury_account_id' => $cashbox->id, 'is_active' => true],
            ['payment_method_id' => $wallet->id, 'treasury_account_id' => null, 'is_active' => true],
        ]);
        PosSession::factory()->create([
            'pos_device_id' => $device->id,
            'location_id' => $this->branch->id,
            'opened_by' => $this->actor->id,
        ]);

        Livewire::test(EditPosDevicePaymentMethod::class, ['record' => $configuration->getRouteKey()])
            ->fillForm(['payment_methods' => [
                ['payment_method_id' => $cash->id, 'treasury_account_id' => $cashbox->id, 'is_active' => true],
                ['payment_method_id' => $wallet->id, 'treasury_account_id' => null, 'is_active' => true],
            ]])
            ->call('save')
            ->assertHasFormErrors(['pos_device_id'])
            ->assertNotified(__('pos.notifications.payment_configuration_failed'));

        $this->assertDatabaseHas('pos_device_payment_methods', [
            'pos_device_id' => $device->id,
            'payment_method_id' => $wallet->id,
            'treasury_account_id' => null,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('pos_device_payment_methods', 2);
    }

    public function test_non_cash_assignments_can_change_during_a_shift_but_cash_mapping_cannot(): void
    {
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $cash = PaymentMethod::factory()->create(['type' => 'cash', 'available_in_pos' => true, 'is_active' => true]);
        $vodafone = PaymentMethod::factory()->create([
            'name' => 'Vodafone Cash',
            'type' => 'wallet',
            'available_in_pos' => true,
            'is_active' => true,
        ]);
        $fawry = PaymentMethod::factory()->create([
            'name' => 'Fawry',
            'type' => 'wallet',
            'available_in_pos' => true,
            'is_active' => true,
        ]);
        $cashbox = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        $management = app(PosDeviceManagement::class);
        $management->configurePaymentMethods($this->actor, $device, [
            ['payment_method_id' => $cash->id, 'treasury_account_id' => $cashbox->id, 'is_active' => true],
            ['payment_method_id' => $fawry->id, 'treasury_account_id' => null, 'is_active' => true],
        ]);
        $session = PosSession::factory()->create([
            'pos_device_id' => $device->id,
            'location_id' => $this->branch->id,
            'cash_treasury_account_id' => $cashbox->id,
            'opened_by' => $this->actor->id,
        ]);

        $management->configurePaymentMethods($this->actor, $device, [
            ['payment_method_id' => $cash->id, 'treasury_account_id' => $cashbox->id, 'is_active' => true],
            ['payment_method_id' => $vodafone->id, 'treasury_account_id' => null, 'is_active' => true],
            ['payment_method_id' => $fawry->id, 'treasury_account_id' => null, 'is_active' => false],
        ]);

        $this->assertDatabaseHas('pos_device_payment_methods', [
            'pos_device_id' => $device->id,
            'payment_method_id' => $cash->id,
            'treasury_account_id' => $cashbox->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('pos_device_payment_methods', [
            'pos_device_id' => $device->id,
            'payment_method_id' => $vodafone->id,
            'treasury_account_id' => null,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('pos_device_payment_methods', [
            'pos_device_id' => $device->id,
            'payment_method_id' => $fawry->id,
            'treasury_account_id' => null,
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('pos_device_payment_methods', 3);
        $this->assertSame($cashbox->id, $session->fresh()->cash_treasury_account_id);

        $otherCashbox = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        try {
            $management->configurePaymentMethods($this->actor, $device, [
                ['payment_method_id' => $cash->id, 'treasury_account_id' => $otherCashbox->id, 'is_active' => true],
                ['payment_method_id' => $vodafone->id, 'treasury_account_id' => null, 'is_active' => true],
                ['payment_method_id' => $fawry->id, 'treasury_account_id' => null, 'is_active' => false],
            ]);
            $this->fail('The active shift cashbox was changed.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [__('pos.validation.cash_mapping_locked_during_shift')],
                $exception->errors()['pos_device_id'],
            );
        }

        $this->assertDatabaseHas('pos_device_payment_methods', [
            'pos_device_id' => $device->id,
            'payment_method_id' => $cash->id,
            'treasury_account_id' => $cashbox->id,
            'is_active' => true,
        ]);
    }

    public function test_device_payment_methods_can_be_configured_and_edited_together(): void
    {
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $cash = PaymentMethod::factory()->create(['type' => 'cash', 'available_in_pos' => true, 'is_active' => true, 'sort_order' => 1]);
        $card = PaymentMethod::factory()->create(['type' => 'card', 'available_in_pos' => true, 'is_active' => true, 'sort_order' => 2]);
        $wallet = PaymentMethod::factory()->create(['type' => 'wallet', 'available_in_pos' => true, 'is_active' => true, 'sort_order' => 3]);
        $bankTransfer = PaymentMethod::factory()->create(['type' => 'bank_transfer', 'available_in_pos' => true, 'is_active' => true, 'sort_order' => 4]);
        PaymentMethod::factory()->create(['type' => 'wallet', 'available_in_pos' => false]);
        $drawer = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        $bank = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'type' => 'bank']);
        $cardSettlement = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'type' => 'provider_settlement']);
        TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'type' => 'provider_settlement']);

        $this->assertSame(
            [$cash->id => $cash->name, $card->id => $card->name, $wallet->id => $wallet->name, $bankTransfer->id => $bankTransfer->name],
            app(PosReadModels::class)->paymentMethodOptions($this->actor, (int) $device->id),
        );
        $this->assertSame([], app(PosReadModels::class)->treasuryAccountOptions($this->actor, (int) $device->id, (int) $wallet->id));
        $this->assertSame([], app(PosReadModels::class)->treasuryAccountOptions($this->actor, (int) $device->id, (int) $bankTransfer->id));

        Livewire::test(CreatePosDevicePaymentMethod::class)
            ->assertFormFieldExists('payment_methods')
            ->fillForm([
                'pos_device_id' => $device->id,
                'payment_methods' => [
                    ['payment_method_id' => $cash->id, 'treasury_account_id' => $drawer->id, 'is_active' => true],
                    ['payment_method_id' => $card->id, 'treasury_account_id' => null, 'is_active' => true],
                    ['payment_method_id' => $wallet->id, 'treasury_account_id' => null, 'is_active' => true],
                    ['payment_method_id' => $bankTransfer->id, 'treasury_account_id' => null, 'is_active' => true],
                ],
            ])->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseCount('pos_device_payment_methods', 4);
        $cashConfiguration = PosDevicePaymentMethod::where('pos_device_id', $device->id)
            ->where('payment_method_id', $cash->id)->firstOrFail();
        Livewire::test(EditPosDevicePaymentMethod::class, ['record' => $cashConfiguration->getRouteKey()])
            ->assertFormFieldIsDisabled('pos_device_id')
            ->assertFormFieldExists('payment_methods')
            ->fillForm(['payment_methods' => [[
                'payment_method_id' => $cash->id,
                'treasury_account_id' => $drawer->id,
                'is_active' => false,
            ]]])->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas('pos_device_payment_methods', [
            'pos_device_id' => $device->id, 'payment_method_id' => $cash->id, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('pos_device_payment_methods', [
            'pos_device_id' => $device->id, 'payment_method_id' => $wallet->id,
            'treasury_account_id' => null, 'is_active' => false,
        ]);
        $this->assertDatabaseCount('pos_device_payment_methods', 4);

        try {
            app(PosDeviceManagement::class)->configurePaymentMethods($this->actor, $device, [
                ['payment_method_id' => $card->id, 'treasury_account_id' => $cardSettlement->id, 'is_active' => true],
                ['payment_method_id' => $card->id, 'treasury_account_id' => $cardSettlement->id, 'is_active' => true],
            ]);
            $this->fail('Duplicate payment methods were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_methods', $exception->errors());
        }
        try {
            app(PosDeviceManagement::class)->configurePaymentMethods($this->actor, $device, [
                ['payment_method_id' => $card->id, 'treasury_account_id' => $cardSettlement->id, 'is_active' => true],
                ['payment_method_id' => $wallet->id, 'treasury_account_id' => null, 'is_active' => true],
            ]);
            $this->fail('A non-cash payment method accepted a Treasury account.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('treasury_account_id', $exception->errors());
        }
        $this->assertDatabaseCount('pos_device_payment_methods', 4);
    }

    public function test_device_payment_configuration_rejects_foreign_accounts_and_devices(): void
    {
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $foreignBranch = Location::create(['name' => 'Foreign branch', 'type' => 'branch', 'is_active' => true]);
        $foreignAccount = TreasuryAccount::factory()->create(['location_id' => $foreignBranch->id]);
        $account = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        $cash = PaymentMethod::factory()->create(['type' => 'cash', 'available_in_pos' => true, 'is_active' => true]);

        try {
            app(PosDeviceManagement::class)->configurePaymentMethods($this->actor, $device, [[
                'payment_method_id' => $cash->id,
                'treasury_account_id' => $foreignAccount->id,
                'is_active' => true,
            ]]);
            $this->fail('A foreign branch account was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('treasury_account_id', $exception->errors());
        }

        $foreignDevice = PosDevice::factory()->create(['location_id' => $foreignBranch->id]);
        try {
            app(PosDeviceManagement::class)->configurePaymentMethods($this->actor, $foreignDevice, [[
                'payment_method_id' => $cash->id,
                'treasury_account_id' => $account->id,
                'is_active' => true,
            ]]);
            $this->fail('A device from an unauthorized branch was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('pos_device_payment_methods', 0);
    }

    public function test_device_manager_permission_is_required_to_create_devices(): void
    {
        $this->actor->revokePermissionTo(PosPermissions::DEVICES_MANAGE);

        Livewire::test(CreatePosDevice::class)->assertForbidden();

        $this->assertDatabaseCount('pos_devices', 0);
    }

    public function test_seeders_do_not_create_clearing_accounts_and_keep_non_cash_mappings_accountless(): void
    {
        $administrator = $this->provisionTestSuperAdmin();
        config(['treasury.seed_actor_id' => $administrator->id]);
        $bank = TreasuryAccount::factory()->create(['location_id' => $this->branch->id,
            'type' => 'bank', 'bank_name' => 'Existing bank', 'created_by' => $administrator->id]);
        $inactiveClearing = TreasuryAccount::factory()->create(['location_id' => $this->branch->id,
            'type' => 'bank', 'bank_name' => 'POS Clearing', 'is_active' => false, 'created_by' => $administrator->id]);
        $this->app->instance('env', 'local');
        try {
            $this->seed(PosDatabaseSeeder::class);
            $accounts = TreasuryAccount::orderBy('id')->get()->toArray();
            $configurations = PosDevicePaymentMethod::orderBy('id')->get()->toArray();
            $this->seed(PosDatabaseSeeder::class);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame($accounts, TreasuryAccount::orderBy('id')->get()->toArray());
        $this->assertSame($configurations, PosDevicePaymentMethod::orderBy('id')->get()->toArray());
        $this->assertFalse($inactiveClearing->fresh()->is_active);
        $this->assertSame('Existing bank', $bank->fresh()->bank_name);
        $device = PosDevice::where('location_id', $this->branch->id)->firstOrFail();
        $cardMapping = $device->paymentConfigurations()->whereHas('paymentMethod', fn ($query) => $query->where('type', 'card'))->firstOrFail();
        $this->assertNull($cardMapping->treasury_account_id);
        $this->assertSame(0, TreasuryAccount::where('location_id', $this->branch->id)->where('type', 'provider_settlement')->count());
    }

    public function test_demo_sessions_use_existing_drawer_funds_without_creating_treasury_entries(): void
    {
        $administrator = $this->provisionTestSuperAdmin();
        config(['treasury.seed_actor_id' => $administrator->id]);
        $this->app->instance('env', 'local');
        try {
            $this->seed(PosDatabaseSeeder::class);
            $device = PosDevice::orderBy('id')->firstOrFail();
            $drawerId = $device->paymentConfigurations()->whereHas('paymentMethod', fn ($query) => $query->where('type', 'cash'))
                ->value('treasury_account_id');
            $treasury = app(TreasuryService::class);
            $treasury->post($administrator, $treasury->createDraft($administrator, [
                'type' => 'cash_deposit', 'account_id' => $drawerId, 'amount' => '100',
                'effective_date' => today()->toDateString(), 'source_type' => 'external_other',
                'source_reference' => 'DEMO-FLOAT', 'reason' => 'Existing drawer funds',
            ], (string) Str::uuid7()));
            $entries = DB::table('treasury_entries')->orderBy('id')->get()->toJson();
            $this->seed(PosSessionSeeder::class);
            $this->seed(PosSessionSeeder::class);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $session = PosSession::where('status', 'open')->where('opened_by', $administrator->id)->firstOrFail();
        $this->assertSame((int) $drawerId, (int) $session->cash_treasury_account_id);
        $this->assertSame('100.0000', $session->opening_cash);
        $this->assertSame($entries, DB::table('treasury_entries')->orderBy('id')->get()->toJson());
    }

    public function test_clearing_account_seeder_does_not_create_artificial_settlement_accounts(): void
    {
        $administrator = $this->provisionTestSuperAdmin();
        config(['treasury.seed_actor_id' => $administrator->id]);
        PaymentMethod::factory()->create(['type' => 'card', 'available_in_pos' => true, 'is_active' => true]);
        $this->branch->update(['name' => str_repeat('Branch', 40)]);
        $before = TreasuryAccount::count();
        $this->seed(PosBranchClearingAccountSeeder::class);
        $this->assertSame($before, TreasuryAccount::count());
    }

    public function test_local_seeders_create_devices_and_usable_mappings_once_per_branch(): void
    {
        $administrator = $this->provisionTestSuperAdmin();
        config(['treasury.seed_actor_id' => $administrator->id]);
        $this->app->instance('env', 'local');
        try {
            $this->seed(PosDatabaseSeeder::class);
            $devices = PosDevice::orderBy('id')->get()->toArray();
            $mappings = PosDevicePaymentMethod::orderBy('id')->get()->toArray();
            $this->seed(PosDatabaseSeeder::class);
        } finally {
            $this->app->instance('env', 'testing');
        }

        $this->assertSame($devices, PosDevice::orderBy('id')->get()->toArray());
        $this->assertSame($mappings, PosDevicePaymentMethod::orderBy('id')->get()->toArray());
        $device = PosDevice::where('location_id', $this->branch->id)->firstOrFail();
        $this->assertSame(3, $device->paymentConfigurations()->count());
        $this->assertCount(3, app(PosReadModels::class)->paymentMethodOptions($administrator, $device->id));
        foreach ($device->paymentConfigurations as $mapping) {
            $this->assertArrayHasKey($mapping->treasury_account_id, app(PosReadModels::class)->treasuryAccountOptions(
                $administrator, $device->id, $mapping->payment_method_id,
            ));
        }
        $this->assertDatabaseCount('pos_sessions', 0);
        $this->assertDatabaseCount('treasury_entries', 0);
    }
}
