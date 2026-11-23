<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Contracts\TreasuryPaymentGateway;
use App\Modules\Payments\Data\PostedPaymentReference;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentsAccess;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\PaymentsPermissions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PaymentsFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::where('code', 'payments')->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Payments test branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo(PaymentsPermissions::all());
        $this->method = PaymentMethod::factory()->create();
    }

    private function draft(array $changes = [], ?string $key = null): Payment
    {
        return app(PaymentService::class)->createDraft($this->actor, array_replace([
            'location_id' => $this->branch->id, 'payment_method_id' => $this->method->id,
            'direction' => 'in', 'amount' => '8000.0001',
        ], $changes), $key ?? (string) Str::uuid7());
    }

    private function invalid(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid operation accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function denied(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Unauthorized operation accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_draft_post_retries_and_treasury_reference(): void
    {
        $key = (string) Str::uuid7();
        $draft = $this->draft(key: $key);
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->posted_at);
        $this->assertSame($draft->id, $this->draft(['amount' => '08000.00010'], $key)->id);
        $posted = app(PaymentService::class)->post($this->actor, $draft);
        $before = $posted->getAttributes();
        $this->assertSame('posted', $posted->status);
        $this->assertSame($before, app(PaymentService::class)->post($this->actor, $posted)->getAttributes());
        $this->assertSame($posted->id, $this->draft(key: $key)->id);
        $evidence = PostedPaymentReference::fromPayment($posted);
        $this->assertSame('8000.0001', $evidence->amount);
        $this->assertSame('EGP', $evidence->currency);
        $this->assertFalse(app()->bound(TreasuryPaymentGateway::class));
        foreach (['vendor_payments', 'vendor_payment_allocations', 'payment_allocations', 'payment_refunds', 'pos_cash_movements', 'stock_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_conflicting_retries_protected_fields_and_invalid_inputs(): void
    {
        $key = (string) Str::uuid7();
        $this->draft(key: $key);
        foreach ([['amount' => '1'], ['direction' => 'out'], ['notes' => 'changed']] as $change) {
            $this->invalid(fn () => $this->draft($change, $key));
        }
        foreach ([['status' => 'posted'], ['origin_module' => 'sales'], ['posted_by' => $this->actor->id], ['currency' => 'USD'], ['direction' => 'incoming'], ['source_document_type' => 'invoice']] as $change) {
            $this->invalid(fn () => $this->draft($change));
        }
        $this->invalid(fn () => $this->draft(key: 'invalid'));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_controlled_methods_and_references_are_checked_again_at_posting(): void
    {
        $this->method->update(['requires_reference' => true]);
        $this->invalid(fn () => $this->draft());
        $draft = $this->draft(['external_reference' => 'BANK-1']);
        $this->method->update(['is_active' => false]);
        $this->invalid(fn () => app(PaymentService::class)->post($this->actor, $draft));
        $this->invalid(fn () => $this->draft(['external_reference' => 'BANK-2']));
        $this->invalid(fn () => $this->draft(['payment_method_id' => 999999]));
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_method_management_reuses_records_and_does_not_rewrite_codes_or_types(): void
    {
        $methods = app(PaymentMethods::class);
        $record = $methods->save($this->actor, ['name' => 'Renamed cash'], $this->method);
        $this->assertSame($this->method->id, $record->id);
        $this->assertDatabaseCount('payment_methods', 1);
        $this->invalid(fn () => $methods->save($this->actor, ['type' => 'bank_transfer'], $record));
        $this->invalid(fn () => $methods->save($this->actor, ['code' => 'BAD', 'name' => 'Bad', 'type' => 'unknown']));
        $this->actor->revokePermissionTo(PaymentsPermissions::MANAGE_METHODS);
        $this->denied(fn () => $methods->save($this->actor, ['name' => 'Denied'], $record));
    }

    public function test_history_and_stale_instances_cannot_mutate_or_delete_posted_records(): void
    {
        $draft = $this->draft();
        $this->invalid(fn () => $draft->update(['status' => 'posted']));
        $draft->refresh();
        $stale = $draft->fresh();
        $posted = app(PaymentService::class)->post($this->actor, $draft);
        $this->invalid(fn () => $posted->update(['amount' => '1']));
        $this->invalid(fn () => $stale->update(['notes' => 'Stale update']));
        $this->invalid(fn () => $posted->delete());
        $this->assertSame('8000.0001', $posted->fresh()->amount);
    }

    public function test_reversal_is_opposite_linked_unique_and_idempotent(): void
    {
        $service = app(PaymentService::class);
        $posted = $service->post($this->actor, $this->draft(['direction' => 'out']));
        $before = $posted->getAttributes();
        $key = (string) Str::uuid7();
        $reversal = $service->reverse($this->actor, $posted, $key, 'Incorrect entry');
        $this->assertSame('in', $reversal->direction);
        $this->assertSame($posted->amount, $reversal->amount);
        $this->assertSame($posted->id, $reversal->reversal_of_id);
        $this->assertSame($before, $posted->fresh()->getAttributes());
        $this->assertSame($reversal->id, $service->reverse($this->actor, $posted, $key, 'Incorrect entry')->id);
        $this->invalid(fn () => $service->reverse($this->actor, $posted, $key, 'Changed reason'));
        $this->invalid(fn () => $service->reverse($this->actor, $posted, (string) Str::uuid7(), 'Duplicate'));
        $this->invalid(fn () => $service->reverse($this->actor, $reversal, (string) Str::uuid7(), 'Reverse reversal'));
        $this->invalid(fn () => $service->reverse($this->actor, $this->draft(), (string) Str::uuid7(), 'Draft'));
        $this->assertDatabaseCount('payments', 3);
    }

    public function test_module_actor_and_permissions_are_enforced_even_on_retries(): void
    {
        $key = (string) Str::uuid7();
        $draft = $this->draft(key: $key);
        $this->actor->revokePermissionTo(PaymentsPermissions::CREATE);
        $this->denied(fn () => $this->draft(key: $key));
        $this->actor->revokePermissionTo(PaymentsPermissions::POST);
        $this->denied(fn () => app(PaymentService::class)->post($this->actor, $draft));
        $this->actor->givePermissionTo(PaymentsPermissions::POST);
        Module::where('code', 'payments')->update(['is_active' => false]);
        $this->denied(fn () => app(PaymentService::class)->post($this->actor, $draft));
        Module::where('code', 'payments')->update(['is_active' => true]);
        $this->actor->update(['is_active' => false]);
        $this->denied(fn () => app(PaymentService::class)->post($this->actor, $draft));
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_cross_location_view_does_not_grant_cross_location_posting(): void
    {
        $draft = $this->draft();
        $other = Location::create(['name' => 'Payments test branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor->locations()->attach($other);
        setPermissionsTeamId($other->id);
        $this->actor->unsetRelation('permissions')->givePermissionTo([PaymentsPermissions::VIEW, PaymentsPermissions::VIEW_ALL_LOCATIONS, PaymentsPermissions::POST]);
        app(PaymentsAccess::class)->authorize($this->actor, PaymentsPermissions::VIEW, $this->branch->id);
        $this->assertSame(1, app(PaymentsAccess::class)->scope(Payment::query(), $this->actor)->count());
        $this->denied(fn () => app(PaymentService::class)->post($this->actor, $draft));
        $this->actor->revokePermissionTo(PaymentsPermissions::VIEW_ALL_LOCATIONS);
        $this->assertSame(0, app(PaymentsAccess::class)->scope(Payment::query(), $this->actor)->count());
    }

    public function test_ordinary_users_get_no_sensitive_permissions(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->locations()->attach($this->branch);
        $this->assertSame(0, $user->getAllPermissions()->count());
        $this->denied(fn () => app(PaymentService::class)->createDraft($user, [
            'location_id' => $this->branch->id, 'payment_method_id' => $this->method->id, 'direction' => 'in', 'amount' => '1',
        ], (string) Str::uuid7()));
    }

    public function test_outer_failure_rolls_back_creation_and_posting(): void
    {
        try {
            DB::transaction(function (): void {
                app(PaymentService::class)->post($this->actor, $this->draft());
                throw new \RuntimeException('Simulated failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_legacy_sales_insert_shapes_and_all_existing_statuses_remain_unchanged(): void
    {
        foreach (['draft', 'pending', 'posted', 'failed', 'cancelled'] as $status) {
            $id = DB::table('payments')->insertGetId([
                'payment_number' => 'LEGACY-'.Str::uuid7(), 'location_id' => $this->branch->id,
                'payment_method_id' => $this->method->id, 'direction' => 'in', 'amount' => '1.2345',
                'status' => $status, 'created_by' => $this->actor->id,
            ]);
            $record = Payment::findOrFail($id);
            $before = DB::table('payments')->where('id', $id)->first();
            $this->assertNull($record->currency);
            $this->assertNull($record->origin_module);
            $this->invalid(fn () => app(PaymentService::class)->post($this->actor, $record));
            $this->invalid(fn () => PostedPaymentReference::fromPayment($record));
            $this->assertEquals($before, DB::table('payments')->where('id', $id)->first());
        }
    }

    public function test_draft_and_tampered_payload_cannot_become_posted_evidence(): void
    {
        $draft = $this->draft();
        $this->invalid(fn () => PostedPaymentReference::fromPayment($draft));
        DB::table('payments')->where('id', $draft->id)->update(['amount' => '9']);
        $this->invalid(fn () => app(PaymentService::class)->post($this->actor, $draft));
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_reversal_requires_its_own_permission_and_cannot_bypass_existing_refunds(): void
    {
        $service = app(PaymentService::class);
        $posted = $service->post($this->actor, $this->draft());
        $this->actor->revokePermissionTo(PaymentsPermissions::REVERSE);
        $this->denied(fn () => $service->reverse($this->actor, $posted, (string) Str::uuid7(), 'Denied'));
        $this->actor->givePermissionTo(PaymentsPermissions::REVERSE);
        DB::table('payment_refunds')->insert([
            'refund_number' => 'REFUND-'.Str::uuid7(), 'payment_id' => $posted->id,
            'payment_method_id' => $this->method->id, 'amount' => '1', 'status' => 'draft', 'created_by' => $this->actor->id,
        ]);
        $this->invalid(fn () => $service->reverse($this->actor, $posted, (string) Str::uuid7(), 'Requires owner'));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_other_workflow_states_and_factory_legacy_records_are_not_adopted(): void
    {
        $legacy = Payment::factory()->create(['location_id' => $this->branch->id, 'payment_method_id' => $this->method->id, 'created_by' => $this->actor->id]);
        $this->invalid(fn () => app(PaymentService::class)->post($this->actor, $legacy));
        foreach (['pending', 'failed', 'cancelled'] as $status) {
            $draft = $this->draft();
            DB::table('payments')->where('id', $draft->id)->update(['status' => $status]);
            $this->invalid(fn () => app(PaymentService::class)->post($this->actor, $draft));
            $this->assertSame($status, $draft->fresh()->status);
        }
    }

    public function test_original_positive_amount_database_constraint_is_preserved(): void
    {
        $draft = $this->draft();
        $this->expectException(QueryException::class);
        DB::table('payments')->where('id', $draft->id)->update(['amount' => '0']);
    }

    public function test_mutations_require_document_view_authority_as_well_as_action_authority(): void
    {
        $draft = $this->draft();
        $this->actor->revokePermissionTo(PaymentsPermissions::VIEW);
        $this->denied(fn () => app(PaymentService::class)->post($this->actor, $draft));
        $this->denied(fn () => $this->draft());
        $this->assertSame('draft', $draft->fresh()->status);
    }
}
