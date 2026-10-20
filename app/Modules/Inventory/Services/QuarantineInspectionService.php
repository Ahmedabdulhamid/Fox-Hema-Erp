<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Models\QuarantineInspection;
use App\Modules\Inventory\Models\QuarantineReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QuarantineInspectionService
{
    /** Observations append custody evidence; they never decide recoverability, release goods or alter value. */
    public function record(User $actor, QuarantineReceipt $receipt, string $observations, string $submissionId): QuarantineInspection
    {
        Validator::make(['observations' => $observations, 'submission_id' => $submissionId], [
            'observations' => 'required|string|max:16000', 'submission_id' => 'required|uuid',
        ])->validate();

        return DB::transaction(function () use ($actor, $receipt, $observations, $submissionId): QuarantineInspection {
            ModuleRegistryLock::acquire();
            $receipt = QuarantineReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            app(InventoryAccess::class)->authorize($actor, InventoryPermissions::QUARANTINE_VIEW, (int) $receipt->location_id);
            app(InventoryAccess::class)->authorize($actor, InventoryPermissions::QUARANTINE_INSPECT, (int) $receipt->location_id);
            if ($receipt->allocation?->salesReturn?->status !== 'posted') {
                throw ValidationException::withMessages(['record' => __('returns.posting_evidence_invalid')]);
            }
            $hash = hash('sha256', json_encode([$receipt->id, $actor->id, $observations], JSON_THROW_ON_ERROR));
            $existing = QuarantineInspection::where('submission_id', strtolower($submissionId))->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['record' => __('returns.changed')]);
                }

                return $existing;
            }
            $id = DB::table('quarantine_inspections')->insertGetId([
                'quarantine_receipt_id' => $receipt->id, 'submission_id' => strtolower($submissionId), 'observations' => $observations,
                'inspected_by' => $actor->id, 'inspected_at' => now(), 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditLogger::class)->record('quarantine_inspection_recorded', actor: $actor,
                metadata: ['quarantine_receipt_uuid' => $receipt->uuid, 'location_id' => $receipt->location_id, 'inspection_id' => $id]);

            return QuarantineInspection::findOrFail($id);
        }, 3);
    }
}
