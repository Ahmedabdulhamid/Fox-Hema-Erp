<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentNumberService
{
    /** Global financial identities are separate from existing location-scoped document types. */
    public const FINANCIAL_SOURCES = [
        'PM' => ['TPM', 'payment_methods', 'code'],
        'CUS' => ['TCU', 'payment_customers', 'code'],
        'RCPT' => ['TRCPT', 'payments', 'payment_number'],
        'ALLOC' => ['TALLOC', 'payment_allocations', 'code'],
        'RFND' => ['TRFND', 'payment_refunds', 'refund_number'],
        'TR' => ['TA', 'treasury_accounts', 'code'],
        'CR' => ['TCR', 'treasury_operations', 'code'],
        'CP' => ['TCP', 'treasury_operations', 'code'],
        'TRF' => ['TTRF', 'treasury_operations', 'code'],
        'SP' => ['TSP', 'vendor_payments', 'payment_number'],
        'OB' => ['TOB', 'treasury_operations', 'code'],
        'REV' => ['TREV', 'treasury_operations', 'code'],
    ];

    private array $installedConnections = [];

    public function installed(): bool
    {
        $connection = DB::connection();
        $key = $connection->getName().':'.$connection->getDatabaseName();

        return $this->installedConnections[$key] ??= Schema::hasTable('document_numbers');
    }

    public const SOURCES = [
        'SO' => ['sales_orders', 'order_number'], 'SD' => ['sales_deliveries', 'delivery_number'],
        'INV' => ['sales_invoices', 'invoice_number'], 'SR' => ['sales_returns', 'return_number'],
        'PO' => ['purchase_orders', 'order_number'], 'GRN' => ['purchase_receipts', 'receipt_number'],
        'BILL' => ['vendor_bills', 'bill_number'], 'TRF' => ['stock_transfers', 'transfer_number'],
        'SM' => ['stock_movements', 'uuid'], 'SA' => ['inventory_size_allocations', 'reference'],
        'QC' => ['quarantine_receipts', 'uuid'],
        'CN' => ['sales_credit_notes', 'credit_note_number'],
    ];

    /** Allocate inside the owning document transaction, after its authorization and replay checks. */
    public function allocate(int $locationId, string $type, string $uuid): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Document numbering requires the owning document transaction.');
        }
        if (! $this->installed()) {
            throw ValidationException::withMessages(['number' => __('documents.migration_required')]);
        }
        if (! isset(self::SOURCES[$type]) || ! Str::isUuid($uuid)) {
            throw ValidationException::withMessages(['number' => __('documents.invalid_identity')]);
        }
        ModuleRegistryLock::acquire();
        $uuid = strtolower($uuid);
        $existing = DB::table('document_numbers')->where('document_type', $type)->where('document_uuid', $uuid)->lockForUpdate()->first();
        if ($existing !== null) {
            if ((int) $existing->location_id !== $locationId) {
                throw ValidationException::withMessages(['number' => __('documents.identity_conflict')]);
            }

            return $existing->number;
        }
        [$table] = self::SOURCES[$type];
        if (Schema::hasTable($table) && DB::table($table)->where('uuid', $uuid)
            ->when($type === 'CN', fn ($query) => $query->where(fn ($draft) => $draft->where('status', '!=', 'draft')->orWhereNull('submission_payload_hash')))
            ->exists()) {
            throw ValidationException::withMessages(['number' => __('documents.historical_numbering_forbidden')]);
        }
        $location = Location::whereKey($locationId)->lockForUpdate()->firstOrFail();
        if (! preg_match('/^[A-Z0-9][A-Z0-9-]{0,39}$/D', $location->code ?? '')) {
            throw ValidationException::withMessages(['number' => __('documents.location_code_required')]);
        }
        DB::table('document_number_sequences')->insertOrIgnore([
            'location_id' => $locationId, 'document_type' => $type, 'location_code' => $location->code, 'last_number' => 0,
        ]);
        $sequence = DB::table('document_number_sequences')->where('location_id', $locationId)->where('document_type', $type)->lockForUpdate()->first();
        $next = (int) $sequence->last_number;
        do {
            if ($next >= 999999999999) {
                throw ValidationException::withMessages(['number' => __('documents.sequence_exhausted')]);
            }
            $number = $sequence->location_code.'-'.$type.'-'.now()->format('Y').'-'.str_pad((string) ++$next, 6, '0', STR_PAD_LEFT);
            if (strlen($number) > 60) {
                throw ValidationException::withMessages(['number' => __('documents.location_code_required')]);
            }
        } while ($this->exists($number));
        DB::table('document_number_sequences')->where('id', $sequence->id)->update(['last_number' => $next]);
        DB::table('document_numbers')->insert([
            'location_id' => $locationId, 'document_type' => $type, 'document_uuid' => $uuid,
            'number' => $number, 'sequence_value' => $next, 'created_at' => now(),
        ]);

        return $number;
    }

    /** Reuse the registry and locked sequences; central ownership never requires a fabricated location. */
    public function allocateFinancial(string $prefix, string $uuid): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Financial numbering requires the owning creation transaction.');
        }
        if (! isset(self::FINANCIAL_SOURCES[$prefix]) || ! Str::isUuid($uuid)) {
            throw ValidationException::withMessages(['number' => __('documents.invalid_identity')]);
        }
        [$type, $table, $column] = self::FINANCIAL_SOURCES[$prefix];
        ModuleRegistryLock::acquire();
        $uuid = strtolower($uuid);
        $existing = DB::table('document_numbers')->where('document_type', $type)->where('document_uuid', $uuid)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->location_id !== null) {
                throw ValidationException::withMessages(['number' => __('documents.identity_conflict')]);
            }

            return $existing->number;
        }
        if (DB::table($table)->where('uuid', $uuid)->exists()) {
            throw ValidationException::withMessages(['number' => __('documents.historical_numbering_forbidden')]);
        }
        DB::table('document_number_sequences')->insertOrIgnore([
            'location_id' => null, 'document_type' => $type, 'global_scope' => $type, 'location_code' => $prefix, 'last_number' => 0,
        ]);
        $sequence = DB::table('document_number_sequences')->where('global_scope', $type)->lockForUpdate()->first();
        $next = (int) $sequence->last_number;
        do {
            $number = self::financialCode($prefix, ++$next);
        } while ($this->exists($number) || DB::table($table)->where($column, $number)->exists());
        DB::table('document_number_sequences')->where('id', $sequence->id)->update(['last_number' => $next]);
        DB::table('document_numbers')->insert([
            'location_id' => null, 'document_type' => $type, 'document_uuid' => $uuid, 'number' => $number,
            'sequence_value' => $next, 'created_at' => now(),
        ]);

        return $number;
    }

    public static function financialCode(string $prefix, int $sequence): string
    {
        if (! isset(self::FINANCIAL_SOURCES[$prefix]) || $sequence < 1 || $sequence > 999999999999) {
            throw ValidationException::withMessages(['number' => __('documents.sequence_exhausted')]);
        }

        return $prefix.'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /** Indexed collision checks protect manually supplied legacy references; never MAX(number). */
    private function exists(string $number): bool
    {
        if (DB::table('document_numbers')->where('number', $number)->exists()) {
            return true;
        }
        foreach (self::SOURCES as [$table, $column]) {
            if ($column !== 'uuid' && Schema::hasTable($table) && DB::table($table)->where($column, $number)->exists()) {
                return true;
            }
        }

        return false;
    }
}
