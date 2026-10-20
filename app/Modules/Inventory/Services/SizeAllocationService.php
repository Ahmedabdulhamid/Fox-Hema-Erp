<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\InventoryCosting\Services\SizeAllocationValuationService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SizeAllocationService
{
    public function create(User $actor, array $data, string $submissionId): SizeAllocation
    {
        return DB::transaction(function () use ($actor, $data, $submissionId): SizeAllocation {
            ModuleRegistryLock::acquire();
            $data = $this->validate($actor, $data);
            if (trim($submissionId) === '' || strlen($submissionId) > 255) {
                $this->invalid('submission', 'A valid submission identity is required.');
            }
            $key = hash('sha256', $submissionId);
            $hash = hash('sha256', json_encode([$actor->id, $data], JSON_THROW_ON_ERROR));
            $existing = SizeAllocation::where('submission_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->authorize($actor, InventoryPermissions::ALLOCATIONS_MANAGE, $existing->location_id);
                if (! hash_equals($existing->submission_hash, $hash)) {
                    $this->invalid('submission', 'This submission identity has a different allocation payload.');
                }

                return $existing;
            }
            $source = ProductVariant::where('unallocated_product_id', $data['product_id'])->lockForUpdate()->firstOrFail();
            $uuid = (string) Str::uuid7();
            $allocation = SizeAllocation::create([
                'uuid' => $uuid, 'reference' => app(DocumentNumberService::class)->allocate((int) $data['location_id'], 'SA', $uuid), 'submission_key' => $key, 'submission_hash' => $hash,
                'product_id' => $data['product_id'], 'location_id' => $data['location_id'],
                'source_variant_id' => $source->id, 'status' => 'draft', 'created_by' => $actor->id,
                'notes' => $data['notes'],
            ]);
            foreach ($data['items'] as $item) {
                $allocation->items()->create($item);
            }

            return $allocation->refresh()->load('items');
        }, 3);
    }

    public function update(User $actor, SizeAllocation $allocation, array $data, string $revision): SizeAllocation
    {
        return DB::transaction(function () use ($actor, $allocation, $data, $revision): SizeAllocation {
            $allocation = $this->lock($actor, $allocation, InventoryPermissions::ALLOCATIONS_MANAGE);
            $this->draft($allocation);
            $this->checkRevision($allocation, $revision);
            $data = $this->validate($actor, $data);
            if ($data['product_id'] !== (int) $allocation->product_id || $data['location_id'] !== (int) $allocation->location_id) {
                $this->invalid('product_id', 'Draft product and location cannot be changed. Cancel and create another allocation.');
            }
            foreach ($allocation->items()->lockForUpdate()->get() as $item) {
                $item->delete();
            }
            foreach ($data['items'] as $item) {
                $allocation->items()->create($item);
            }
            $allocation->notes = $data['notes'];
            $allocation->save();

            return $allocation->refresh()->load('items');
        }, 3);
    }

    public function post(User $actor, SizeAllocation $allocation, string $revision): SizeAllocation
    {
        return DB::transaction(function () use ($actor, $allocation, $revision): SizeAllocation {
            $allocation = $this->lock($actor, $allocation, InventoryPermissions::ALLOCATIONS_POST);
            app(InventoryAccess::class)->authorizePosting($actor, (int) $allocation->location_id);
            if ($allocation->status === 'posted') {
                return $allocation->load('items.movements');
            }
            $this->draft($allocation);
            $this->checkRevision($allocation, $revision);
            $this->validateTargets($allocation);
            app(SizeAllocationValuationService::class)->post($allocation, $actor);
            $allocation->fill(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $actor->id])->save();

            return $allocation->refresh()->load('items.movements');
        }, 3);
    }

    public function cancel(User $actor, SizeAllocation $allocation, string $revision): SizeAllocation
    {
        return DB::transaction(function () use ($actor, $allocation, $revision): SizeAllocation {
            $allocation = $this->lock($actor, $allocation, InventoryPermissions::ALLOCATIONS_MANAGE);
            if ($allocation->status === 'cancelled') {
                return $allocation;
            }
            $this->draft($allocation);
            $this->checkRevision($allocation, $revision);
            $allocation->status = 'cancelled';
            $allocation->save();

            return $allocation;
        }, 3);
    }

    public function revision(SizeAllocation $allocation): string
    {
        return hash('sha256', json_encode([$allocation->getAttributes(), $allocation->items()->orderBy('id')->get()->map->getAttributes()->all()], JSON_THROW_ON_ERROR));
    }

    private function lock(User $actor, SizeAllocation $allocation, string $permission): SizeAllocation
    {
        ModuleRegistryLock::acquire();
        $allocation = SizeAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
        $this->authorize($actor, $permission, (int) $allocation->location_id);

        return $allocation;
    }

    private function authorize(User $actor, string $permission, int $locationId): void
    {
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        app(InventoryAccess::class)->authorize($actor, InventoryPermissions::ALLOCATIONS_VIEW, $locationId);
        app(InventoryAccess::class)->authorize($actor, $permission, $locationId);
    }

    private function validate(User $actor, array $data): array
    {
        $data = Validator::make($data, [
            'product_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1', 'max:99'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,14}(?:\.0{1,4})?$/D'],
        ])->validate();
        $data['product_id'] = (int) $data['product_id'];
        $data['location_id'] = (int) $data['location_id'];
        $data['notes'] = $data['notes'] ?? null;
        $this->authorize($actor, InventoryPermissions::ALLOCATIONS_MANAGE, $data['location_id']);
        $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
        $source = $product->variants()->where('unallocated_product_id', $product->id)->lockForUpdate()->first();
        if ($source === null || ! $source->is_active || $source->size_id !== null || $source->color_id !== null) {
            $this->invalid('product_id', 'Choose a product with an active unallocated variant.');
        }
        $data['items'] = array_values(array_map(function (array $item) use ($product): array {
            $variant = $product->variants()->whereKey($item['variant_id'])->lockForUpdate()->first();
            if ($variant === null || $variant->isUnallocated() || ! $variant->is_active || $variant->size_id === null || ! $variant->size?->is_active) {
                $this->invalid('items', 'Choose active size variants belonging to the same product.');
            }
            $quantity = BigDecimal::of((string) $item['quantity']);

            return ['variant_id' => (int) $variant->id, 'quantity' => (string) $quantity->toScale(4)];
        }, array_values($data['items'])));
        $data['items'] = array_values(array_filter($data['items'], fn (array $item): bool => BigDecimal::of($item['quantity'])->isPositive()));
        if ($data['items'] === []) {
            $this->invalid('items', 'Allocate a positive number of individual pieces.');
        }
        usort($data['items'], fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);

        return $data;
    }

    private function validateTargets(SizeAllocation $allocation): void
    {
        Product::whereKey($allocation->product_id)->lockForUpdate()->firstOrFail();
        $source = ProductVariant::whereKey($allocation->source_variant_id)->lockForUpdate()->firstOrFail();
        if ((int) $source->unallocated_product_id !== (int) $allocation->product_id
            || (int) $source->product_id !== (int) $allocation->product_id || ! $source->is_active
            || $source->size_id !== null || $source->color_id !== null) {
            $this->invalid('source', 'The unallocated source identity is inconsistent.');
        }
        $items = $allocation->items()->orderBy('variant_id')->lockForUpdate()->get();
        if ($items->isEmpty() || $items->count() > 99) {
            $this->invalid('items', 'An allocation requires between one and 99 size lines.');
        }
        foreach ($items as $item) {
            $variant = ProductVariant::whereKey($item->variant_id)->lockForUpdate()->firstOrFail();
            if ($variant->isUnallocated() || ! $variant->is_active || (int) $variant->product_id !== (int) $allocation->product_id
                || $variant->size_id === null || ! $variant->size?->is_active
                || ! preg_match('/^\d{1,14}\.0000$/D', $item->quantity) || ! BigDecimal::of($item->quantity)->isPositive()
                || $item->movements()->exists()) {
                $this->invalid('items', 'Allocation lines are invalid or already linked to stock movements.');
            }
        }
    }

    private function draft(SizeAllocation $allocation): void
    {
        if ($allocation->status !== 'draft') {
            $this->invalid('status', 'Only draft allocations can be changed.');
        }
    }

    private function checkRevision(SizeAllocation $allocation, string $revision): void
    {
        if (! hash_equals($this->revision($allocation), $revision)) {
            $this->invalid('revision', 'This allocation changed. Reload before continuing.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
