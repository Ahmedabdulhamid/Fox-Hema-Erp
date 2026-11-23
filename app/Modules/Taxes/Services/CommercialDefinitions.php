<?php

namespace App\Modules\Taxes\Services;

use App\Filament\RequestDisplayData;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Services\DiscountManager;
use App\Modules\Products\Models\Product;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommercialDefinitions
{
    public function eligible(string $kind, string $usage, int|string|null $supplierId = null): Builder
    {
        abort_unless(in_array($kind, ['taxes', 'discounts'], true) && in_array($usage, ['sales', 'purchases'], true), 403);
        $tax = $kind === 'taxes';
        $at = $tax ? now()->toDateString() : now()->toDateTimeString();
        $start = $tax ? 'effective_from' : 'starts_at';
        $end = $tax ? 'effective_to' : 'ends_at';

        return ($tax ? Tax::query() : Discount::query())->where('is_active', true)->whereIn('usage', [$usage, 'both'])
            ->when($tax && $usage === 'purchases', fn ($query) => $query->where(fn ($query) => $query
                ->whereNotNull('purchase_treatment')->orWhere('rate', '0')))
            ->when(! $tax, fn ($query) => $query->where('scope', 'product')
                ->where(fn ($query) => $supplierId === null
                    ? $query->whereNull('supplier_id')
                    : $query->whereNull('supplier_id')->orWhere('supplier_id', $supplierId)))
            ->where(fn ($query) => $query->whereNull($start)->orWhere($start, '<=', $at))
            ->where(fn ($query) => $query->whereNull($end)->orWhere($end, '>=', $at));
    }

    /** @param array<array-key, array<string, mixed>> $items */
    public function primeDisplayInputs(array $items, string $usage, int|string|null $supplierId = null): void
    {
        if (RequestAuthorizationFacts::readBoundary() === null) {
            return;
        }
        foreach (['taxes' => 'tax_ids', 'discounts' => 'discount_id'] as $kind => $field) {
            $selections = [];
            foreach ($items as $item) {
                $ids = $kind === 'taxes' ? ($item[$field] ?? []) : array_filter([$item[$field] ?? null]);
                if (! is_array($ids) || count($ids) > 20 || collect($ids)->contains(fn ($id): bool => ! is_scalar($id) || ! ctype_digit((string) $id))) {
                    continue;
                }
                $selections[json_encode($ids, JSON_THROW_ON_ERROR)] = $ids;
            }
            $ids = array_values(array_unique(array_merge([], ...array_values($selections))));
            $records = RequestDisplayData::remember('commercial-display-union:'.$this->selectionKey($kind, $usage, $ids, $supplierId),
                fn (): Collection => $ids === [] ? collect() : $this->eligible($kind, $usage, $supplierId)->whereKey($ids)->orderBy('id')->get()->keyBy('id'));
            foreach ($selections as $selection) {
                RequestDisplayData::remember($this->selectionKey($kind, $usage, $selection, $supplierId),
                    fn (): Collection => $records->only(array_map(fn ($id) => is_scalar($id) && ctype_digit((string) $id) ? (ltrim((string) $id, '0') ?: '0') : $id, $selection))->sortBy('id')->values());
            }
        }
    }

    /** @param array<array-key, int|string> $ids */
    public function selected(string $kind, string $usage, array $ids, int|string|null $supplierId = null): Collection
    {
        $query = $this->eligible($kind, $usage, $supplierId);

        return RequestDisplayData::remember($this->selectionKey($kind, $usage, $ids, $supplierId),
            fn (): Collection => $ids === [] ? collect() : $query->whereKey($ids)->orderBy('id')->get());
    }

    /** @param array<array-key, int|string> $ids */
    private function selectionKey(string $kind, string $usage, array $ids, int|string|null $supplierId = null): string
    {
        $ids = array_values(array_map(fn ($id) => is_scalar($id) && ctype_digit((string) $id) ? (ltrim((string) $id, '0') ?: '0') : $id, $ids));
        sort($ids);

        return 'commercial-selected:'.json_encode([$kind, $usage, $supplierId, $ids], JSON_THROW_ON_ERROR);
    }

    public function taxes(Product $product, string $usage, array $input, string $prefix = ''): Collection
    {
        $query = $this->eligible('taxes', $usage);
        if (! array_key_exists('tax_ids', $input)) {
            return $query->whereHas('products', fn ($query) => $query->whereKey($product->id))->orderBy('id')->get();
        }
        $ids = $input['tax_ids'];
        $validator = Validator::make(['tax_ids' => $ids], ['tax_ids' => ['present', 'array', 'max:20'], 'tax_ids.*' => ['integer', 'min:1', 'distinct']]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn (array $messages, string $field): array => [$prefix.$field => $messages])->all());
        }
        if ($ids === []) {
            return collect();
        }
        $definitions = $this->selected('taxes', $usage, $ids);
        if ($definitions->count() !== count($ids)) {
            $this->invalid($prefix.'tax_ids', 'Choose active, effective taxes applicable to this document.');
        }

        return $definitions;
    }

    public function discount(Product $product, string $usage, array $input, string $prefix = '', int|string|null $supplierId = null): ?Discount
    {
        $query = $this->eligible('discounts', $usage, $supplierId);
        if (! array_key_exists('discount_id', $input)) {
            // Omitted fields preserve existing API defaults; explicit null always means no discount.
            $defaults = $query->whereHas('products', fn ($query) => $query->whereKey($product->id))->orderBy('id')->get();
            if ($defaults->count() > 1) {
                $this->invalid($prefix.'discount_id', 'Choose one discount or explicitly choose no discount.');
            }

            return $defaults->first();
        }
        $id = $input['discount_id'];
        if ($id === null || $id === '') {
            return null;
        }
        if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id)) {
            $this->invalid($prefix.'discount_id', 'Choose an eligible configured discount.');
        }
        $discount = $this->selected('discounts', $usage, [$id], $supplierId)->first();
        if ($discount === null) {
            $this->invalid($prefix.'discount_id', 'Choose an eligible configured discount.');
        }

        return $discount;
    }

    public function create(User $actor, string $kind, string $usage, array $data, int|string|null $supplierId = null): int
    {
        return DB::transaction(function () use ($actor, $kind, $usage, $data, $supplierId): int {
            $access = app(DefinitionAccess::class);
            $access->authorize($actor, $kind, 'view');
            $access->authorize($actor, $kind, 'create');
            $type = $data['type'] ?? null;
            $data['usage'] = $usage;
            $data['is_active'] = true;
            if ($kind === 'taxes') {
                $data['price_inclusion'] = 'excluded';
            } else {
                $data['scope'] = 'product';
                $data['is_stackable'] = false;
            }
            if (! in_array($type, ['percentage', 'fixed'], true)
                || ($kind === 'taxes' && ($data['price_inclusion'] !== 'excluded'))
                || ($kind === 'discounts' && ($data['scope'] !== 'product' || $data['is_stackable']))) {
                $this->invalid('type', 'Inline creation supports active excluded taxes or single nonstackable product discounts for this document.');
            }
            if ($usage === 'purchases' && $kind === 'discounts') {
                if (! is_scalar($supplierId) || ! ctype_digit((string) $supplierId)) {
                    $this->invalid('supplier_id', 'Choose the supplier before creating a purchase discount.');
                }
                $data['supplier_id'] = (int) $supplierId;
            }
            $manager = $kind === 'taxes' ? TaxManager::class : DiscountManager::class;
            $definition = app($manager)->save($actor, $data);
            $supplierId = $kind === 'discounts' ? ($data['supplier_id'] ?? null) : null;
            if (! $this->eligible($kind, $usage, $supplierId)->whereKey($definition->id)->exists()) {
                $this->invalid('is_active', 'The new definition must be currently eligible for this document.');
            }

            return (int) $definition->id;
        });
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
