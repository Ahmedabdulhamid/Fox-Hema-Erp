<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentDrafts
{
    /**
     * Captures review evidence only. Generic posting cannot safely value physical adjustments.
     *
     * @param  array{uuid: string, variant_id: int, location_id: int, observed_on_hand: string, observed_reserved: string, physical_quantity: string, reason: string, notes?: ?string}  $data
     */
    public function save(User $actor, array $data): string
    {
        $access = app(InventoryAccess::class);
        $access->authorize($actor, InventoryPermissions::BALANCES, $data['location_id']);
        $access->authorize($actor, InventoryPermissions::POST, $data['location_id']);
        Validator::make($data, [
            'uuid' => ['required', 'uuid'], 'variant_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'],
            'observed_on_hand' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D'],
            'observed_reserved' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D'],
            'physical_quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D'],
            'reason' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:8000'],
        ])->validate();
        $payload = [
            'variant_id' => $data['variant_id'], 'location_id' => $data['location_id'],
            'observed_on_hand' => (string) BigDecimal::of($data['observed_on_hand'])->toScale(4),
            'observed_reserved' => (string) BigDecimal::of($data['observed_reserved'])->toScale(4),
            'physical_quantity' => (string) BigDecimal::of($data['physical_quantity'])->toScale(4),
            'reason' => trim($data['reason']), 'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'created_by' => $actor->id,
        ];
        if ($payload['reason'] === '') {
            throw ValidationException::withMessages(['reason' => __('An adjustment requires an explicit reason.')]);
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $payload, $hash, $access): string {
            ModuleRegistryLock::acquire();
            $access->authorize($actor, InventoryPermissions::POST, $data['location_id']);
            $existing = DB::table('inventory_adjustment_drafts')->where('uuid', $data['uuid'])->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['physical_quantity' => __('This adjustment identity was already used with different details.')]);
                }

                return $existing->uuid;
            }
            ProductVariant::findOrFail($data['variant_id']);
            $balance = StockBalance::where('variant_id', $data['variant_id'])->where('location_id', $data['location_id'])->lockForUpdate()->firstOrFail();
            if (! BigDecimal::of($balance->quantity_on_hand)->isEqualTo($payload['observed_on_hand'])
                || ! BigDecimal::of($balance->quantity_reserved)->isEqualTo($payload['observed_reserved'])) {
                throw ValidationException::withMessages(['physical_quantity' => __('Stock changed while you were counting. Refresh and review the quantities again.')]);
            }
            if (BigDecimal::of($payload['physical_quantity'])->isLessThan($balance->quantity_reserved)) {
                throw ValidationException::withMessages(['physical_quantity' => __('Physical quantity cannot be less than reserved stock.')]);
            }
            DB::table('inventory_adjustment_drafts')->insert($payload + ['uuid' => $data['uuid'], 'payload_hash' => $hash, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

            return $data['uuid'];
        }, attempts: 3);
    }

    public function policy(User $actor, int $variantId, int $locationId, ?string $threshold, int $days): void
    {
        app(InventoryAccess::class)->authorize($actor, InventoryPermissions::BALANCES, $locationId);
        app(InventoryAccess::class)->authorize($actor, InventoryPermissions::POST, $locationId);
        Validator::make(['threshold' => $threshold, 'days' => $days], [
            'threshold' => ['nullable', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D'], 'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ])->validate();
        ProductVariant::findOrFail($variantId);
        DB::table('inventory_stock_policies')->upsert([
            'variant_id' => $variantId, 'location_id' => $locationId,
            'reorder_threshold' => $threshold === null ? null : (string) BigDecimal::of($threshold)->toScale(4),
            'dead_stock_days' => $days, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ], ['variant_id', 'location_id'], ['reorder_threshold', 'dead_stock_days', 'updated_by', 'updated_at']);
    }
}
