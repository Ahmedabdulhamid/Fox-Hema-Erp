<?php

namespace App\Modules\Taxes\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Services\DiscountManager;
use App\Modules\Products\Models\Product;
use App\Modules\Taxes\Models\Tax;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommercialDefinitions
{
    public function eligible(string $kind, string $usage): Builder
    {
        abort_unless(in_array($kind, ['taxes', 'discounts'], true) && in_array($usage, ['sales', 'purchases'], true), 403);
        $tax = $kind === 'taxes';
        $at = $tax ? now()->toDateString() : now()->toDateTimeString();
        $start = $tax ? 'effective_from' : 'starts_at';
        $end = $tax ? 'effective_to' : 'ends_at';

        return ($tax ? Tax::query() : Discount::query())->where('is_active', true)->whereIn('usage', [$usage, 'both'])
            ->when(! $tax, fn ($query) => $query->where('scope', 'product'))
            ->where(fn ($query) => $query->whereNull($start)->orWhere($start, '<=', $at))
            ->where(fn ($query) => $query->whereNull($end)->orWhere($end, '>=', $at));
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
        $definitions = $query->whereKey($ids)->orderBy('id')->get();
        if ($definitions->count() !== count($ids)) {
            $this->invalid($prefix.'tax_ids', 'Choose active, effective taxes applicable to this document.');
        }

        return $definitions;
    }

    public function discount(Product $product, string $usage, array $input, string $prefix = ''): ?Discount
    {
        $query = $this->eligible('discounts', $usage);
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
        $discount = $query->whereKey($id)->first();
        if ($discount === null) {
            $this->invalid($prefix.'discount_id', 'Choose an eligible configured discount.');
        }

        return $discount;
    }

    public function create(User $actor, string $kind, string $usage, array $data): int
    {
        return DB::transaction(function () use ($actor, $kind, $usage, $data): int {
            $access = app(DefinitionAccess::class);
            $access->authorize($actor, $kind, 'view');
            $access->authorize($actor, $kind, 'create');
            if (($data['type'] ?? null) !== 'percentage' || ($data['usage'] ?? null) !== $usage
                || ! ($data['is_active'] ?? false) || ($kind === 'taxes' && ($data['price_inclusion'] ?? null) !== 'excluded')
                || ($kind === 'discounts' && (($data['scope'] ?? null) !== 'product' || ($data['is_stackable'] ?? false)))) {
                $this->invalid('type', 'Inline creation supports active excluded percentage taxes or single percentage product discounts for this document.');
            }
            $amount = $kind === 'taxes' ? 'rate' : 'value';
            if ($usage === 'purchases' && (! is_scalar($data[$amount] ?? null) || ! preg_match('/^\d+(\.\d+)?$/D', (string) $data[$amount]) || ! BigDecimal::of((string) $data[$amount])->isZero())) {
                $this->invalid($amount, 'Only zero-valued purchase definitions can be created inline until inventory-cost treatment is approved.');
            }
            $manager = $kind === 'taxes' ? TaxManager::class : DiscountManager::class;
            $definition = app($manager)->save($actor, $data);
            if (! $this->eligible($kind, $usage)->whereKey($definition->id)->exists()) {
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
