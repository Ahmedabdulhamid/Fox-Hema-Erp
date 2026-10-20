<?php

namespace Tests\Unit;

use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Models\PosCashMovement;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosSessionTender;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PosModelsTest extends TestCase
{
    public function test_factories_make_each_model_with_supplied_owner_keys(): void
    {
        foreach ([
            [PosDevice::class, ['location_id' => 42, 'registered_by' => 42]],
            [PosSession::class, ['pos_device_id' => 42, 'location_id' => 42, 'opened_by' => 42, 'closed_by' => 42, 'closing_approved_by' => 42]],
            [PosTransaction::class, ['pos_device_id' => 42, 'pos_session_id' => 42, 'location_id' => 42, 'cashier_id' => 42, 'sales_order_id' => 42, 'sales_invoice_id' => 42, 'sales_return_id' => 42, 'sales_credit_note_id' => 42]],
            [PosCashMovement::class, ['pos_session_id' => 42, 'corrects_movement_id' => 42, 'created_by' => 42, 'approved_by' => 42]],
            [PosSessionTender::class, ['pos_session_id' => 42, 'verified_by' => 42]],
        ] as [$modelClass, $owners]) {
            $model = $modelClass::factory()->make($owners);
            $this->assertInstanceOf($modelClass, $model);
            foreach ($owners as $field => $value) {
                $this->assertSame($value, $model->{$field});
            }
        }
    }

    public function test_transaction_payloads_round_trip_as_arrays_and_attempts_are_integers(): void
    {
        $request = ['items' => [['variant_id' => 10, 'quantity' => '1.2500']], 'offline' => true];
        $result = ['invoice_id' => 11, 'status' => 'completed'];
        $transaction = new PosTransaction([
            'request_payload' => $request,
            'result_payload' => $result,
            'attempts' => '3',
        ]);
        $this->assertSame($request, $transaction->request_payload);
        $this->assertSame($result, $transaction->result_payload);
        $this->assertSame(3, $transaction->attempts);
        $this->assertSame($request, json_decode($transaction->getAttributes()['request_payload'], true));
        $this->assertSame($result, json_decode($transaction->getAttributes()['result_payload'], true));
        $transaction->fill(['request_payload' => null, 'result_payload' => null]);
        $this->assertNull($transaction->request_payload);
        $this->assertNull($transaction->result_payload);
    }

    public function test_cash_amounts_preserve_precision_and_signed_differences(): void
    {
        $session = new PosSession([
            'opening_cash' => '100.1234', 'expected_cash' => '150.1234',
            'counted_cash' => '140.1234', 'cash_difference' => '-10.0000',
        ]);
        $this->assertSame('100.1234', $session->opening_cash);
        $this->assertSame('150.1234', $session->expected_cash);
        $this->assertSame('140.1234', $session->counted_cash);
        $this->assertSame('-10.0000', $session->cash_difference);
        $movement = new PosCashMovement(['amount' => '12.3456']);
        $this->assertSame('12.3456', $movement->amount);
        $tender = new PosSessionTender([
            'expected_amount' => '50.1234', 'verified_amount' => '45.1234',
            'difference_amount' => '-5.0000',
        ]);
        $this->assertSame('50.1234', $tender->expected_amount);
        $this->assertSame('45.1234', $tender->verified_amount);
        $this->assertSame('-5.0000', $tender->difference_amount);
        $session->fill(['expected_cash' => null, 'counted_cash' => null, 'cash_difference' => null]);
        $tender->fill(['verified_amount' => null, 'difference_amount' => null]);
        $this->assertNull($session->expected_cash);
        $this->assertNull($session->counted_cash);
        $this->assertNull($session->cash_difference);
        $this->assertNull($tender->verified_amount);
        $this->assertNull($tender->difference_amount);
        $this->assertFalse((new PosDevice(['is_active' => 0]))->is_active);
    }

    public function test_lifecycle_timestamps_cast_values_and_preserve_nulls(): void
    {
        foreach ([
            [PosDevice::class, ['registered_at', 'last_seen_at', 'revoked_at']],
            [PosSession::class, ['opened_at', 'closing_started_at', 'closed_at', 'closing_approved_at']],
            [PosTransaction::class, ['client_occurred_at', 'server_received_at', 'processed_at', 'last_attempt_at']],
            [PosCashMovement::class, ['approved_at', 'occurred_at']],
            [PosSessionTender::class, ['verified_at']],
        ] as [$modelClass, $fields]) {
            $model = new $modelClass;
            foreach ($fields as $field) {
                $model->fill([$field => '2026-10-11 12:30:45']);
                $this->assertInstanceOf(Carbon::class, $model->{$field});
                $this->assertSame('2026-10-11 12:30:45', $model->{$field}->format('Y-m-d H:i:s'));
                $model->fill([$field => null]);
                $this->assertNull($model->{$field});
            }
        }
    }

    public function test_actor_relationships_and_cash_corrections_associate_the_correct_keys(): void
    {
        $actor = (new User)->forceFill(['id' => 42]);
        foreach ([
            [PosDevice::class, 'registeredBy', 'registered_by'],
            [PosSession::class, 'openedBy', 'opened_by'],
            [PosSession::class, 'closedBy', 'closed_by'],
            [PosSession::class, 'closingApprovedBy', 'closing_approved_by'],
            [PosTransaction::class, 'cashier', 'cashier_id'],
            [PosCashMovement::class, 'createdBy', 'created_by'],
            [PosCashMovement::class, 'approvedBy', 'approved_by'],
            [PosSessionTender::class, 'verifiedBy', 'verified_by'],
        ] as [$modelClass, $relation, $key]) {
            $model = new $modelClass;
            $model->{$relation}()->associate($actor);
            $this->assertSame(42, $model->{$key});
            $this->assertTrue($model->{$relation}->is($actor));
        }
        $original = (new PosCashMovement)->forceFill(['id' => 12]);
        $correction = new PosCashMovement(['movement_type' => 'correction', 'direction' => 'out']);
        $correction->correctsMovement()->associate($original);
        $this->assertSame(12, $correction->corrects_movement_id);
        $this->assertTrue($correction->correctsMovement->is($original));
        $this->assertSame('pos_cash_movements.corrects_movement_id', $original->corrections()->getQualifiedForeignKeyName());
    }

    public function test_transaction_sales_links_have_singular_inverse_relationships(): void
    {
        foreach ([
            [SalesOrder::class, 'salesOrder', 'sales_order_id'],
            [SalesInvoice::class, 'salesInvoice', 'sales_invoice_id'],
            [SalesReturn::class, 'salesReturn', 'sales_return_id'],
            [SalesCreditNote::class, 'salesCreditNote', 'sales_credit_note_id'],
        ] as [$modelClass, $relation, $key]) {
            $document = (new $modelClass)->forceFill(['id' => 42]);
            $transaction = new PosTransaction;
            $transaction->{$relation}()->associate($document);
            $this->assertSame(42, $transaction->{$key});
            $this->assertTrue($transaction->{$relation}->is($document));
            $this->assertInstanceOf(HasOne::class, $document->posTransaction());
            $transaction->{$relation}()->dissociate();
            $this->assertNull($transaction->{$key});
            $this->assertNull($transaction->{$relation});
        }
    }
}
