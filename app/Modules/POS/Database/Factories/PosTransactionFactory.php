<?php

namespace App\Modules\POS\Database\Factories;

use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PosTransaction> */
class PosTransactionFactory extends Factory
{
    protected $model = PosTransaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'pos_session_id' => PosSession::factory(),
            'pos_device_id' => fn (array $attributes): int => PosSession::findOrFail($attributes['pos_session_id'])->pos_device_id,
            'location_id' => fn (array $attributes): int => PosSession::findOrFail($attributes['pos_session_id'])->location_id,
            'cashier_id' => fn (array $attributes): int => PosSession::findOrFail($attributes['pos_session_id'])->opened_by,
            'client_transaction_id' => fake()->unique()->uuid(),
            'sales_order_id' => null,
            'sales_invoice_id' => null,
            'sales_return_id' => null,
            'sales_credit_note_id' => null,
            'transaction_type' => 'sale',
            'sync_status' => 'received',
            'client_occurred_at' => now(),
            'server_received_at' => null,
            'processed_at' => null,
            'payload_hash' => hash('sha256', fake()->uuid()),
            'request_payload' => null,
            'result_payload' => null,
            'attempts' => 0,
            'last_attempt_at' => null,
            'error_code' => null,
            'error_message' => null,
        ];
    }
}
