<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentNumberService
{
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
