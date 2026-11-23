<?php

namespace Tests\Unit;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\Pages\CreateTreasuryAccount;
use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\TreasuryAccountResource;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\Pages\CreateTreasuryOperation;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\TreasuryOperationResource;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Policies\TreasuryAccountPolicy;
use App\Modules\Treasury\Policies\TreasuryOperationPolicy;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TreasuryInterfaceAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->beforeExecuting(function (): never {
            throw new \LogicException('These tests must not execute SQL or migrations.');
        });
    }

    public function test_denied_treasury_permission_fails_closed(): void
    {
        $actor = new User;
        $access = Mockery::mock(TreasuryAccess::class);
        $access->shouldReceive('operation')->with($actor, TreasuryPermissions::VIEW, 7)->once()->andThrow(new HttpException(403));
        $workspace = new TreasuryWorkspace($access);
        $this->assertFalse($workspace->allows($actor, TreasuryPermissions::VIEW, 7));
        $this->assertFalse($workspace->allows(null, TreasuryPermissions::VIEW, 7));
    }

    public function test_transfer_query_requires_visibility_of_both_accounts(): void
    {
        $actor = new User;
        $access = Mockery::mock(TreasuryAccess::class);
        $access->shouldReceive('scope')->once()->andReturnUsing(fn (Builder $query, User $user): Builder => $query->where('location_id', 7));
        $query = (new TreasuryWorkspace($access))->operations($actor);
        $sql = $query->toSql();
        $this->assertStringContainsString('"account_id" in (select', $sql);
        $this->assertStringContainsString('"destination_account_id" in (select', $sql);
        $this->assertSame(2, count(array_filter($query->getBindings(), fn (mixed $value): bool => $value === 7)));
    }

    public function test_balances_are_not_selected_without_balance_permission(): void
    {
        $access = Mockery::mock(TreasuryAccess::class);
        $access->shouldReceive('scope')->once()->andReturnUsing(fn (Builder $query): Builder => $query->where('location_id', 7));
        $access->shouldReceive('operation')->once()->andThrow(new HttpException(403));
        $sql = (new TreasuryWorkspace($access))->accountsWithBalances(new User)->toSql();
        $this->assertStringContainsString('NULL as ledger_balance', $sql);
        $this->assertStringNotContainsString('treasury_entries', $sql);
    }

    public function test_ledger_history_keeps_local_transfer_legs_and_flags_restricted_documents(): void
    {
        $access = Mockery::mock(TreasuryAccess::class);
        $access->shouldReceive('scope')->twice()->andReturnUsing(fn (Builder $query): Builder => $query->where('location_id', 7));
        $query = (new TreasuryWorkspace($access))->entries(new User);
        $sql = $query->toSql();
        $this->assertStringContainsString('"treasury_entries"', $sql);
        $this->assertStringContainsString('"account_id" in (select', $sql);
        $this->assertStringContainsString('can_view_operation', $sql);
        $this->assertContains('posted', $query->getBindings());
    }

    public function test_cancelled_posted_and_supplier_owned_operations_cannot_post_through_generic_pages(): void
    {
        $workspace = Mockery::mock(TreasuryWorkspace::class);
        $workspace->shouldNotReceive('canOperate');
        $this->app->instance(TreasuryWorkspace::class, $workspace);
        $policy = new TreasuryOperationPolicy;
        foreach ([
            ['status' => 'posted'],
            ['status' => 'draft', 'cancelled_at' => '2026-10-25 12:00:00'],
            ['status' => 'draft', 'vendor_payment_id' => 42],
            ['status' => 'draft', 'payment_id' => 42],
        ] as $attributes) {
            $this->assertFalse($policy->post(new User, (new TreasuryOperation)->setRawAttributes($attributes)));
        }
    }

    public function test_transfer_posting_requires_transfer_authority_in_addition_to_posting(): void
    {
        $actor = new User;
        $operation = (new TreasuryOperation)->setRawAttributes(['status' => 'draft', 'account_id' => 7, 'destination_account_id' => 8]);
        $workspace = Mockery::mock(TreasuryWorkspace::class);
        $workspace->shouldReceive('canOperate')->with($actor, $operation, TreasuryPermissions::POST)->once()->andReturn(true);
        $workspace->shouldReceive('canOperate')->with($actor, $operation, TreasuryPermissions::TRANSFER)->once()->andReturn(false);
        $this->app->instance(TreasuryWorkspace::class, $workspace);
        $this->assertFalse((new TreasuryOperationPolicy)->post($actor, $operation));
    }

    public function test_posted_records_have_no_edit_delete_or_generic_supplier_reversal_path(): void
    {
        $actor = new User;
        $policy = new TreasuryOperationPolicy;
        $operation = (new TreasuryOperation)->setRawAttributes(['status' => 'posted', 'vendor_payment_id' => 42]);
        $this->assertFalse($policy->update($actor, $operation));
        $this->assertFalse($policy->delete($actor, $operation));
        $this->assertFalse($policy->cancel($actor, $operation));
        $this->assertFalse($policy->reverse($actor, $operation));
        $this->assertFalse((new TreasuryAccountPolicy)->delete($actor, new TreasuryAccount));
    }

    public function test_treasury_resources_and_routes_have_explicit_policies(): void
    {
        $this->assertInstanceOf(TreasuryAccountPolicy::class, Gate::getPolicyFor(TreasuryAccount::class));
        $this->assertInstanceOf(TreasuryOperationPolicy::class, Gate::getPolicyFor(TreasuryOperation::class));
        foreach (['filament.admin.pages.treasury', 'filament.admin.pages.treasury-history', 'filament.admin.resources.treasury-accounts.index',
            'filament.admin.resources.treasury-operations.receipts', 'filament.admin.resources.treasury-operations.payments',
            'filament.admin.resources.treasury-operations.transfers', 'filament.admin.resources.vendor-payments.advances'] as $route) {
            $this->assertTrue(app('router')->getRoutes()->hasNamedRoute($route), $route);
        }
    }

    public function test_opening_balance_identity_cannot_be_stranded_by_draft_cancellation(): void
    {
        $operation = (new TreasuryOperation)->setRawAttributes(['status' => 'draft', 'type' => 'opening_balance']);
        $this->assertFalse((new TreasuryOperationPolicy)->cancel(new User, $operation));
    }

    public function test_account_and_voucher_forms_build_against_installed_filament_apis(): void
    {
        $accountPage = app(CreateTreasuryAccount::class);
        $accountSchema = TreasuryAccountResource::form(Schema::make($accountPage));
        $accountFields = array_map(fn ($field): string => $field->getName(), $accountSchema->getComponents());
        $this->assertContains('name', $accountFields);
        $this->assertNotContains('code', $accountFields);
        $page = app(CreateTreasuryOperation::class);
        $page->kind = 'transfer';
        $schema = TreasuryOperationResource::form(Schema::make($page));
        $this->assertContains('destination_account_id', array_map(fn ($field): string => $field->getName(), $schema->getComponents()));
    }
}
