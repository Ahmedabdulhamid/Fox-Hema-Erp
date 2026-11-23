<?php

namespace Tests\Unit;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Filament\CustomerSettlementActions;
use App\Modules\Payments\Filament\Pages\CustomerPaymentHistory;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerSettlementTerms;
use App\Modules\Payments\Services\PaymentsPermissions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerSettlementRulesTest extends TestCase
{
    public function test_cash_credit_and_mixed_terms_preserve_exact_intent_without_marking_invoices_paid(): void
    {
        $terms = app(CustomerSettlementTerms::class);
        foreach ([['cash', '100.0000'], ['credit', '0.0000'], ['mixed', '40.0001']] as [$type,$amount]) {
            $this->assertSame($amount, $terms->validate(['settlement_terms' => $type, 'planned_immediate_amount' => $amount, 'due_date' => '2026-11-01'], '100.0000', '2026-10-26')['planned_immediate_amount']);
        }
        $this->assertNull($terms->validate([], '100.0000', '2026-10-26')['settlement_terms']);
    }

    public function test_mixed_cannot_claim_full_invoice_amount(): void
    {
        $this->expectException(ValidationException::class);
        app(CustomerSettlementTerms::class)->validate(['settlement_terms' => 'mixed', 'planned_immediate_amount' => '100', 'due_date' => '2026-11-01'], '100.0000', '2026-10-26');
    }

    public function test_credit_requires_a_due_date(): void
    {
        $this->expectException(ValidationException::class);
        app(CustomerSettlementTerms::class)->validate(['settlement_terms' => 'credit', 'planned_immediate_amount' => '0'], '100.0000', '2026-10-26');
    }

    public function test_missing_customer_schema_is_detected_without_querying_financial_tables(): void
    {
        Schema::shouldReceive('hasTable')->with('payment_customers')->once()->andReturnFalse();
        $this->assertFalse(app(CustomerAccess::class)->ready());
    }

    public function test_partial_treasury_migration_keeps_customer_settlement_unavailable(): void
    {
        Schema::shouldReceive('hasTable')->with('payment_customers')->once()->andReturnTrue();
        Schema::shouldReceive('hasTable')->with('customer_invoice_accounts')->once()->andReturnTrue();
        Schema::shouldReceive('hasColumn')->with('payments', 'treasury_account_id')->once()->andReturnTrue();
        Schema::shouldReceive('hasColumn')->with('payment_refunds', 'recovery_operation_id')->once()->andReturnFalse();
        $this->assertFalse(app(CustomerAccess::class)->ready());
    }

    public function test_complete_customer_schema_is_checked_once_per_request(): void
    {
        Schema::shouldReceive('hasTable')->with('payment_customers')->once()->andReturnTrue();
        Schema::shouldReceive('hasTable')->with('customer_invoice_accounts')->once()->andReturnTrue();
        Schema::shouldReceive('hasColumn')->with('payments', 'treasury_account_id')->once()->andReturnTrue();
        Schema::shouldReceive('hasColumn')->with('payment_refunds', 'recovery_operation_id')->once()->andReturnTrue();
        $this->assertTrue(app(CustomerAccess::class)->ready());
        $this->assertTrue(app(CustomerAccess::class)->ready());
    }

    public function test_customer_queries_fail_with_setup_message_before_accessing_missing_tables(): void
    {
        Schema::shouldReceive('hasTable')->with('payment_customers')->once()->andReturnFalse();
        try {
            app(CustomerAccess::class)->scope(Customer::query(), new User);
            $this->fail('Missing migrations must stop the query.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame(__('Customer settlement is unavailable until the pending Payments and Treasury migrations are applied.'), $exception->getMessage());
        }
    }

    public function test_sales_settlement_actions_are_hidden_until_schema_is_ready(): void
    {
        Schema::shouldReceive('hasTable')->with('payment_customers')->once()->andReturnFalse();
        $this->assertFalse(CustomerSettlementActions::can(PaymentsPermissions::VIEW));
    }

    public function test_history_returns_setup_state_without_querying_missing_customers(): void
    {
        Schema::shouldReceive('hasTable')->with('payment_customers')->once()->andReturnFalse();
        $page = new class extends CustomerPaymentHistory
        {
            public function setupData(): array
            {
                return $this->getViewData();
            }
        };
        $this->assertSame(['schemaReady' => false], $page->setupData());
    }
}
