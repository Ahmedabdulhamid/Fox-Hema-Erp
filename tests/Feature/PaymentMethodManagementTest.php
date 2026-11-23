<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\Filament\Resources\CustomerPayments\Pages\ViewCustomerPayment;
use App\Modules\Payments\Filament\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use App\Modules\Payments\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Modules\Payments\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Modules\Payments\Filament\Resources\PaymentMethods\Pages\ViewPaymentMethod;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\CustomerTreasury;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['payments', 'sales', 'treasury'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Method management branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PaymentsPermissions::all(), ...SalesPermissions::all(), ...TreasuryPermissions::all()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
    }

    public function test_resource_create_edit_view_status_search_and_filters_use_existing_service(): void
    {
        app()->setLocale('en');
        Livewire::test(CreatePaymentMethod::class)->assertFormFieldDoesNotExist('code')
            ->fillForm(['name' => 'Cash / نقدي', 'type' => 'cash', 'is_active' => true, 'requires_reference' => false, 'sort_order' => 10])
            ->call('create')->assertHasNoFormErrors();
        $method = PaymentMethod::firstOrFail();
        $code = $method->code;
        $this->assertMatchesRegularExpression('/^PM-\d{6}$/', $code);
        Livewire::test(ViewPaymentMethod::class, ['record' => $method->uuid])->assertSuccessful()->assertSee($code);
        Livewire::test(EditPaymentMethod::class, ['record' => $method->uuid])->assertFormFieldDoesNotExist('code')
            ->fillForm(['name' => 'Updated cash', 'sort_order' => 15])->call('save')->assertHasNoFormErrors();
        $this->assertSame($code, $method->fresh()->code);
        $this->assertSame('Updated cash', $method->fresh()->name);
        app(PaymentMethods::class)->save($this->actor, ['name' => 'Bank', 'type' => 'bank_transfer']);
        Livewire::test(ListPaymentMethods::class)->searchTable('Updated')->assertCanSeeTableRecords([$method])->assertCountTableRecords(1)
            ->searchTable('')->filterTable('type', 'cash')->assertCountTableRecords(1)
            ->callAction(TestAction::make('deactivate')->table($method))->assertHasNoActionErrors();
        $this->assertFalse($method->fresh()->is_active);
        Livewire::test(ListPaymentMethods::class)->filterTable('is_active', false)->assertCanSeeTableRecords([$method])->assertCountTableRecords(1)
            ->callAction(TestAction::make('activate')->table($method))->assertHasNoActionErrors();
        $this->assertTrue($method->fresh()->is_active);
    }

    public function test_seeder_is_idempotent_and_preserves_customized_legacy_codes_names_and_status(): void
    {
        DB::table('payment_methods')->insert(['uuid' => (string) Str::uuid7(), 'code' => 'LEGACY-CASH', 'name' => 'Customized cash',
            'type' => 'cash', 'is_active' => false, 'requires_reference' => true, 'available_in_pos' => false, 'sort_order' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('payment_methods')->where('code', 'LEGACY-CASH')->first();
        $seeder = app(PaymentMethodSeeder::class);
        $seeder->run();
        $first = DB::table('payment_methods')->orderBy('id')->get()->toJson();
        $numbers = DB::table('document_numbers')->where('document_type', 'TPM')->orderBy('id')->get()->toJson();
        $seeder->run();
        $this->assertSame($first, DB::table('payment_methods')->orderBy('id')->get()->toJson());
        $this->assertSame($numbers, DB::table('document_numbers')->where('document_type', 'TPM')->orderBy('id')->get()->toJson());
        $this->assertSame((array) $before, (array) DB::table('payment_methods')->where('code', 'LEGACY-CASH')->first());
        $this->assertDatabaseCount('payment_methods', 3);
        $this->assertSame(3, PaymentMethod::distinct()->count('type'));
        $this->assertStringContainsString('تحويل بنكي', PaymentMethod::where('type', 'bank_transfer')->value('name'));
        $this->assertSame(2, (int) DB::table('document_number_sequences')->where('global_scope', 'TPM')->value('last_number'));
    }

    public function test_automatic_codes_roll_back_with_failed_creation_and_remain_immutable(): void
    {
        $service = app(PaymentMethods::class);
        $first = $service->save($this->actor, ['name' => 'First', 'type' => 'cash']);
        try {
            DB::transaction(function () use ($service): void {
                $service->save($this->actor, ['name' => 'Rollback', 'type' => 'cash']);
                throw new \RuntimeException('Abort creation');
            });
        } catch (\RuntimeException) {
        }
        $second = $service->save($this->actor, ['name' => 'Second', 'type' => 'cash']);
        $this->assertSame('PM-000001', $first->code);
        $this->assertSame('PM-000002', $second->code);
        $this->assertDatabaseCount('payment_methods', 2);
        $this->assertTrue(Schema::hasIndex('payment_methods', ['code'], 'unique'));
        $second->code = 'MANUAL';
        try {
            $second->saveQuietly();
            $this->fail('Code mutation accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame('PM-000002', $second->fresh()->code);
        $this->expectException(QueryException::class);
        DB::table('payment_methods')->where('id', $first->id)->update(['code' => 'ALTERED']);
    }

    public function test_readers_cannot_create_edit_or_change_method_status(): void
    {
        $method = app(PaymentMethods::class)->save($this->actor, ['name' => 'Read only', 'type' => 'cash']);
        $this->actor->revokePermissionTo(PaymentsPermissions::MANAGE_METHODS);
        Livewire::test(ListPaymentMethods::class)->assertSuccessful()->assertCanSeeTableRecords([$method]);
        Livewire::test(CreatePaymentMethod::class)->assertForbidden();
        Livewire::test(EditPaymentMethod::class, ['record' => $method->uuid])->assertForbidden();
        try {
            app(PaymentMethods::class)->save($this->actor, ['is_active' => false], $method);
            $this->fail('Permission bypass.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($method->fresh()->is_active);
    }

    public function test_translation_keys_match_and_arabic_resource_validation_is_translated(): void
    {
        $en = Arr::dot(require lang_path('en/payment_methods.php'));
        $ar = Arr::dot(require lang_path('ar/payment_methods.php'));
        $this->assertSame(array_keys($en), array_keys($ar));
        foreach ($en as $key => $value) {
            $this->assertNotSame('', $value);
            $this->assertNotSame('', $ar[$key]);
        }
        app()->setLocale('ar');
        Livewire::test(CreatePaymentMethod::class)->assertSee('الاسم')->assertSee('النوع')
            ->fillForm(['name' => null, 'type' => 'cash'])->call('create')->assertHasFormErrors(['name' => 'required']);
        try {
            app(PaymentMethods::class)->save($this->actor, ['name' => 'Manual', 'type' => 'cash', 'code' => 'MANUAL']);
            $this->fail('Manual code accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('payment_methods.validation.protected'), $exception->errors()['method'][0]);
        }
        try {
            app(PaymentMethods::class)->save($this->actor, ['name' => 'Invalid', 'type' => 'invalid']);
            $this->fail('Invalid type accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('payment_methods.validation.rules.in', ['attribute' => __('payment_methods.fields.type')]), $exception->errors()['type'][0]);
        }
    }

    public function test_sales_method_eligibility_hides_inactive_methods_and_preserves_history(): void
    {
        app(PaymentMethodSeeder::class)->run();
        $cash = PaymentMethod::where('type', 'cash')->firstOrFail();
        $bank = PaymentMethod::where('type', 'bank_transfer')->firstOrFail();
        $card = PaymentMethod::where('type', 'card')->firstOrFail();
        $customer = app(CustomerAccounts::class)->save($this->actor, ['location_id' => $this->branch->id, 'name' => 'Methods customer']);
        $account = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $this->assertEqualsCanonicalizing([$cash->id, $bank->id, $card->id], app(CustomerTreasury::class)->methods()->pluck('id')->all());
        $data = ['customer_id' => $customer->id, 'payment_method_id' => $cash->id, 'treasury_account_id' => $account->id, 'payment_date' => now()->toDateString(), 'amount' => '100'];
        $service = app(CustomerPayments::class);
        $payment = $service->post($this->actor, $service->createDraft($this->actor, $data, (string) Str::uuid7()));
        app(PaymentMethods::class)->save($this->actor, ['is_active' => false], $cash);
        $this->assertNotContains($cash->id, app(CustomerTreasury::class)->methods()->pluck('id')->all());
        Livewire::test(ViewCustomerPayment::class, ['record' => $payment->uuid])
            ->assertSuccessful()->assertSee($cash->name);
        $this->assertSame($cash->id, $payment->fresh()->payment_method_id);
        try {
            $service->createDraft($this->actor, $data, (string) Str::uuid7());
            $this->fail('Inactive cash accepted.');
        } catch (ValidationException) {
        }
        try {
            $service->createDraft($this->actor, array_replace($data, ['payment_method_id' => $card->id, 'external_reference' => 'CARD-REF']), (string) Str::uuid7());
            $this->fail('Card Treasury incompatibility ignored.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_numbering_skips_existing_codes_without_renumbering_legacy_methods(): void
    {
        DB::table('payment_methods')->insert(['uuid' => (string) Str::uuid7(), 'code' => 'PM-000001', 'name' => 'Legacy bank',
            'type' => 'bank_transfer', 'is_active' => true, 'requires_reference' => true, 'available_in_pos' => false, 'sort_order' => 5]);
        $method = app(PaymentMethods::class)->save($this->actor, ['name' => 'New cash', 'type' => 'cash']);
        $this->assertSame('PM-000002', $method->code);
        $this->assertDatabaseHas('payment_methods', ['code' => 'PM-000001', 'name' => 'Legacy bank']);
    }
}
