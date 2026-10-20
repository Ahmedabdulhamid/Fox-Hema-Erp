<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Data\SystemPostingContext;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Services\SalesReturnValuationService;
use App\Modules\InventoryCosting\Services\StockIssueValuationService;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesReturnCostAllocation;
use App\Modules\Sales\Models\SalesReturnItem;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InventoryPostingService
{
    public function post(StockPostingCommand $command, User|SystemPostingContext $actor): StockMovement
    {
        return $this->postMany([$command], $actor)[0];
    }

    /**
     * Uses the default connection and joins outer transactions with savepoints. The document caller
     * owns commit and must retry its entire transaction on deadlock. Results are provisional until commit.
     * Outer callers must acquire ModuleRegistryLock before their document, stock or valuation locks.
     * Standalone posting acquires the same registry lock before any balance upsert or numbering.
     *
     * @param  list<StockPostingCommand>  $commands
     * @return list<StockMovement> Results in input order.
     */
    public function postMany(array $commands, User|SystemPostingContext $actor): array
    {
        if ($commands === [] || count($commands) > 200 || ! array_is_list($commands)) {
            throw new InvalidArgumentException('Supply between one and 200 posting commands as a list.');
        }
        $prepared = [];
        foreach ($commands as $index => $command) {
            if (! $command instanceof StockPostingCommand) {
                throw new InvalidArgumentException('Expected StockPostingCommand.');
            }
            $prepared[$index] = $this->prepare($command, $actor);
        }
        $keys = array_column($prepared, 'idempotency_key');
        if (count(array_unique($keys)) !== count($keys)) {
            $this->invalid('operationIdentity', 'Each command in a batch requires a distinct operation identity.');
        }
        uasort($prepared, fn (array $a, array $b): int => [$a['variant_id'], $a['location_id'], $a['idempotency_key']] <=> [$b['variant_id'], $b['location_id'], $b['idempotency_key']]);
        $connection = DB::connection();
        $attempts = $connection->transactionLevel() === 0 ? 3 : 1;
        // Roll back before replaying a global key won concurrently at another balance.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $connection->transaction(function () use ($prepared, $actor): array {
                    ModuleRegistryLock::acquire();
                    $results = [];
                    foreach ($prepared as $index => $payload) {
                        app(InventoryAccess::class)->authorizePosting($actor, $payload['location_id']);
                        if (! ProductVariant::whereKey($payload['variant_id'])->exists()) {
                            $this->invalid('variant', 'The product variant no longer exists.');
                        }
                        $existing = StockMovement::where('idempotency_key', $payload['idempotency_key'])->first();
                        if ($existing !== null) {
                            $results[$index] = $this->replay($existing, $payload);

                            continue;
                        }
                        DB::table('stock_balances')->upsert([
                            'uuid' => (string) Str::uuid7(), 'variant_id' => $payload['variant_id'],
                            'location_id' => $payload['location_id'], 'quantity_on_hand' => '0.0000',
                            'quantity_reserved' => '0.0000', 'quantity_available' => '0.0000',
                            'created_at' => now(), 'updated_at' => now(),
                        ], ['variant_id', 'location_id'], ['variant_id']);
                        $balance = StockBalance::where('variant_id', $payload['variant_id'])->where('location_id', $payload['location_id'])->lockForUpdate()->firstOrFail();
                        // Current read after waiting for the balance lock; replay precedes stock validation.
                        $existing = StockMovement::where('idempotency_key', $payload['idempotency_key'])->lockForUpdate()->first();
                        if ($existing !== null) {
                            $results[$index] = $this->replay($existing, $payload);

                            continue;
                        }
                        if (! array_key_exists('valuation_unit_cost', $payload)
                            && Schema::hasColumn('inventory_cost_states', 'location_id')
                            && DB::table('inventory_cost_states')->where('variant_id', $payload['variant_id'])
                                ->where('location_id', $payload['location_id'])->lockForUpdate()->exists()) {
                            $this->invalid('valuation', 'Location-valued stock requires an integrated stock and valuation workflow.');
                        }
                        $onHand = BigDecimal::of($balance->quantity_on_hand);
                        $reserved = BigDecimal::of($balance->quantity_reserved);
                        if ($onHand->isNegative() || $reserved->isNegative() || $reserved->isGreaterThan($onHand)
                            || ! BigDecimal::of($balance->quantity_available)->isEqualTo($onHand->minus($reserved))) {
                            $this->invalid('balance', 'Existing stock quantities violate the inventory invariant. Reconcile before posting.');
                        }
                        $next = $onHand->plus($payload['quantity']);
                        if ($next->isLessThan($reserved)) {
                            $this->invalid('quantity', 'Insufficient available stock.');
                        }
                        $quantity = $this->decimal((string) $next, 'quantity_on_hand');
                        if ($payload['reverses_movement_id'] !== null) {
                            $original = StockMovement::whereKey($payload['reverses_movement_id'])->lockForUpdate()->firstOrFail();
                            if ($original->valuation_unit_cost !== null || (Schema::hasTable('inventory_cost_entries') && $original->inventoryCostEntry()->exists())) {
                                $this->invalid('reverses', 'Valued movements require a controlled stock and valuation reversal workflow.');
                            }
                            if ($original->posting_payload_hash === null || $original->variant_id !== $payload['variant_id']
                                || $original->location_id !== $payload['location_id'] || ! BigDecimal::of($original->quantity)->negated()->isEqualTo($payload['quantity'])
                                || $original->unit_cost !== $payload['unit_cost']) {
                                $this->invalid('reverses', 'A reversal must exactly compensate a posted movement at its original variant and location.');
                            }
                            if (StockMovement::where('reverses_movement_id', $original->id)->lockForUpdate()->exists()) {
                                $this->invalid('reverses', 'This stock movement has already been reversed.');
                            }
                        }
                        $movement = new StockMovement;
                        $movement->uuid = (string) Str::uuid7();
                        app(DocumentNumberService::class)->allocate((int) $payload['location_id'], 'SM', $movement->uuid);
                        $movement->forceFill($payload)->save();
                        $balance->fill(['quantity_on_hand' => $quantity, 'quantity_available' => (string) $next->minus($reserved)->toScale(4)])->save();
                        $results[$index] = $movement;
                    }
                    ksort($results);

                    return array_values($results);
                }, attempts: $attempts);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 3) {
                    throw $exception;
                }
            }
        }
        throw new InvalidArgumentException('Posting retries exhausted.');
    }

    public function reverse(StockMovement $movement, string $operationIdentity, User|SystemPostingContext $actor, string $reason): StockMovement
    {
        if ($movement->valuation_unit_cost !== null || (Schema::hasTable('inventory_cost_entries') && $movement->inventoryCostEntry()->exists())) {
            $this->invalid('reverses', 'Valued movements require a controlled stock and valuation reversal workflow.');
        }

        return $this->post(new StockPostingCommand(
            $movement->variant, $movement->location, StockMovementType::Adjustment,
            (string) BigDecimal::of($movement->quantity)->negated(), $operationIdentity,
            reference: $movement, notes: $reason, unitCost: $movement->unit_cost, reverses: $movement,
        ), $actor);
    }

    private function prepare(StockPostingCommand $command, User|SystemPostingContext $actor): array
    {
        foreach ([$command->variant, $command->location, $command->reference, $command->reverses, $actor instanceof User ? $actor : null] as $model) {
            if ($model instanceof Model && (! $model->exists || $model->getConnection()->getName() !== DB::connection()->getName())) {
                $this->invalid('reference', 'Posting models must be persisted on the default inventory connection.');
            }
        }
        if (trim($command->operationIdentity) === '' || strlen($command->operationIdentity) > 255) {
            $this->invalid('operationIdentity', 'A nonempty operation identity of at most 255 bytes is required.');
        }
        $quantity = $this->decimal($command->quantity, 'quantity', signed: true);
        $number = BigDecimal::of($quantity);
        $return = $command->reference instanceof SalesReturnCostAllocation;
        if ($command->reference instanceof SalesReturnItem
            || ($return && ($command->type !== StockMovementType::Return || ! $number->isPositive()
                || $command->valuationUnitCost === null || ! $actor instanceof User
                || ! app(SalesReturnValuationService::class)->authorizesCommand($command, $actor)))) {
            $this->invalid('valuation', 'Sales return stock requires the authorized return valuation transaction.');
        }
        if ($number->isZero() || (in_array($command->type, [StockMovementType::Purchase, StockMovementType::TransferIn], true) && $number->isNegative())
            || (in_array($command->type, [StockMovementType::Sale, StockMovementType::TransferOut], true) && $number->isPositive())) {
            $this->invalid('quantity', 'Movement quantity is zero or has an invalid direction for its type.');
        }
        if ($command->type === StockMovementType::Adjustment && trim($command->notes ?? '') === '') {
            $this->invalid('notes', 'An adjustment requires an explicit reason.');
        }
        if ($command->reverses !== null && ($command->type !== StockMovementType::Adjustment || $command->reference?->is($command->reverses) !== true)) {
            $this->invalid('reverses', 'Corrections require an adjustment referencing the original stock movement.');
        }
        if (strlen($command->notes ?? '') > 10000) {
            $this->invalid('notes', 'Notes must not exceed 10000 bytes.');
        }
        $payload = [
            'variant_id' => $command->variant->id, 'location_id' => $command->location->id,
            'type' => $command->type->value, 'quantity' => $quantity,
            'unit_cost' => $command->unitCost === null ? null : $this->decimal($command->unitCost, 'unitCost'),
            'reference_type' => $command->reference?->getMorphClass(), 'reference_id' => $command->reference?->getKey(),
            'idempotency_key' => hash('sha256', $command->operationIdentity),
            'created_by' => $actor instanceof User ? $actor->id : null,
            'system_actor' => $actor instanceof SystemPostingContext ? $actor->name : null,
            'notes' => $command->notes, 'reverses_movement_id' => $command->reverses?->id,
        ];
        // Omit the new key for legacy commands, preserving every existing payload hash.
        if ($command->valuationUnitCost !== null) {
            $allocation = $command->reference instanceof SizeAllocationItem && $command->type === StockMovementType::Adjustment;
            $sale = $command->reference instanceof SalesDeliveryItem && $command->type === StockMovementType::Sale;
            if ($return) {
                $document = $command->reference;
                if ($document->disposition !== 'restock' || $document->quantity !== $quantity
                    || (int) $document->variant_id !== (int) $command->variant->id || (int) $document->location_id !== (int) $command->location->id) {
                    $this->invalid('valuation', 'Return movement does not match its original allocation.');
                }
            } elseif ($sale) {
                if (! $actor instanceof User || ! app(StockIssueValuationService::class)->authorizesCommand($command, $actor)) {
                    $this->invalid('valuation', 'Sales issue costs must be computed inside the authorized delivery posting workflow.');
                }
            } elseif ($allocation) {
                if (! $actor instanceof User || DB::connection()->transactionLevel() === 0) {
                    $this->invalid('valuation', 'Size allocation requires the authorized document transaction.');
                }
                $document = $command->reference->allocation;
                app(InventoryAccess::class)->authorize($actor, InventoryPermissions::ALLOCATIONS_VIEW, (int) $document->location_id);
                app(InventoryAccess::class)->authorize($actor, InventoryPermissions::ALLOCATIONS_POST, (int) $document->location_id);
                if ($document->status !== 'draft' || (int) $document->location_id !== (int) $command->location->id
                    || ! in_array((int) $command->variant->id, [(int) $document->source_variant_id, (int) $command->reference->variant_id], true)
                    || ($number->isNegative() !== ((int) $command->variant->id === (int) $document->source_variant_id))) {
                    $this->invalid('valuation', 'The size allocation movement does not match its document.');
                }
            } elseif ($command->type !== StockMovementType::Purchase
                || ! $command->reference instanceof PurchaseReceiptItem
                || ! $actor instanceof User || DB::connection()->transactionLevel() === 0) {
                $this->invalid('valuation', 'Six-decimal receipt valuation requires the authorized receipt transaction.');
            }
            if (! $allocation && ! $sale && ! $return) {
                app(PurchasingAccess::class)->authorize($actor,
                    PurchasingPermissions::RECEIPTS_VIEW, (int) $command->location->id);
                app(PurchasingAccess::class)->authorize($actor,
                    PurchasingPermissions::RECEIPTS_POST, (int) $command->location->id);
            }
            if (! preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $command->valuationUnitCost)) {
                $this->invalid('valuationUnitCost', 'Use a nonnegative decimal cost with at most twelve integer and six fractional digits.');
            }
            $payload['valuation_unit_cost'] = (string) BigDecimal::of($command->valuationUnitCost)->toScale(6);
        }
        $payload['posting_payload_hash'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $payload;
    }

    private function replay(StockMovement $movement, array $payload): StockMovement
    {
        if ($movement->posting_payload_hash === null || ! hash_equals($movement->posting_payload_hash, $payload['posting_payload_hash'])) {
            $this->invalid('operationIdentity', 'This operation identity has already been used with a conflicting payload.');
        }

        return $movement;
    }

    /** Verify stored posting evidence without recomputing valuation or changing the original actor. */
    public function verifyRecordedPayload(StockMovement $movement): void
    {
        $payload = [];
        foreach (['variant_id', 'location_id', 'type', 'quantity', 'unit_cost', 'reference_type', 'reference_id',
            'idempotency_key', 'created_by', 'system_actor', 'notes', 'reverses_movement_id'] as $field) {
            $payload[$field] = $movement->getRawOriginal($field);
        }
        if ($movement->valuation_unit_cost !== null) {
            $payload['valuation_unit_cost'] = $movement->valuation_unit_cost;
        }
        if ($movement->posting_payload_hash === null || ! hash_equals($movement->posting_payload_hash, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)))) {
            $this->invalid('record', 'Stored stock movement posting evidence is inconsistent.');
        }
    }

    private function decimal(string $value, string $field, bool $signed = false): string
    {
        $pattern = $signed ? '/^-?\d{1,14}(?:\.\d{1,4})?$/' : '/^\d{1,14}(?:\.\d{1,4})?$/';
        if (! preg_match($pattern, $value)) {
            $this->invalid($field, 'Use a decimal string with at most 14 integer digits and four fractional digits.');
        }

        return (string) BigDecimal::of($value)->toScale(4);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
