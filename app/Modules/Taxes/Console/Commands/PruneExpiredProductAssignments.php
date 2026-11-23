<?php

namespace App\Modules\Taxes\Console\Commands;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Services\DiscountReferenceChecker;
use App\Modules\Taxes\Models\Tax;
use App\Modules\Taxes\Services\TaxReferenceChecker;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PruneExpiredProductAssignments extends Command
{
    protected $signature = 'commercial:prune-expired-assignments';

    protected $description = 'Remove unreferenced expired product tax and discount assignments';

    public function handle(): int
    {
        $now = now();
        $taxes = $this->prune(
            Tax::class,
            'taxes',
            'product_taxes',
            'tax_id',
            'effective_to',
            'productTaxes',
            $now->toDateString(),
            app(TaxReferenceChecker::class),
        );
        $discounts = $this->prune(
            Discount::class,
            'discounts',
            'discount_products',
            'discount_id',
            'ends_at',
            'discountProducts',
            $now->toDateTimeString(),
            app(DiscountReferenceChecker::class),
        );

        if ($taxes['removed'] > 0) {
            app(ReadModelCache::class)->invalidateAfterCommit('taxes', DB::connection());
        }
        if ($discounts['removed'] > 0) {
            app(ReadModelCache::class)->invalidateAfterCommit('discounts', DB::connection());
        }

        $this->info("Removed product tax links: {$taxes['removed']}; definitions retained for history: {$taxes['preserved']}.");
        $this->info("Removed product discount links: {$discounts['removed']}; definitions retained for history: {$discounts['preserved']}.");

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Tax|Discount>  $model
     * @return array{removed: int, preserved: int}
     */
    private function prune(
        string $model,
        string $definitionsTable,
        string $pivot,
        string $foreignKey,
        string $endColumn,
        string $relation,
        string $cutoff,
        TaxReferenceChecker $referenceChecker,
    ): array {
        $removed = 0;
        $preserved = 0;

        $model::query()
            ->whereNotNull($endColumn)
            ->where($endColumn, '<', $cutoff)
            ->whereHas($relation)
            ->chunkById(100, function ($definitions) use ($model, $definitionsTable, $pivot, $foreignKey, $endColumn, $cutoff, &$removed, &$preserved, $referenceChecker): void {
                foreach ($definitions as $definition) {
                    $result = DB::transaction(function () use ($model, $definitionsTable, $pivot, $foreignKey, $endColumn, $cutoff, $definition, $referenceChecker): array {
                        ModuleRegistryLock::acquire();
                        $current = $model::query()
                            ->whereKey($definition->getKey())
                            ->whereNotNull($endColumn)
                            ->where($endColumn, '<', $cutoff)
                            ->lockForUpdate()
                            ->first();

                        if ($current === null) {
                            return ['removed' => 0, 'preserved' => 0];
                        }
                        if ($referenceChecker->hasHistoricalReferences($current)) {
                            return ['removed' => 0, 'preserved' => 1];
                        }

                        return [
                            'removed' => $this->deleteExpiredLinks(
                                $pivot,
                                $foreignKey,
                                $definitionsTable,
                                $endColumn,
                                (int) $current->getKey(),
                                $cutoff,
                            ),
                            'preserved' => 0,
                        ];
                    }, 3);
                    $removed += $result['removed'];
                    $preserved += $result['preserved'];
                }
            });

        return ['removed' => $removed, 'preserved' => $preserved];
    }

    private function deleteExpiredLinks(string $pivot, string $foreignKey, string $definitionsTable, string $endColumn, int $definitionId, string $cutoff): int
    {
        $expiredLinks = DB::table($pivot)
            ->where($pivot.'.'.$foreignKey, $definitionId)
            ->whereExists(fn (Builder $query): Builder => $query->selectRaw('1')
                ->from($definitionsTable)
                ->whereColumn($definitionsTable.'.id', $pivot.'.'.$foreignKey)
                ->whereNotNull($definitionsTable.'.'.$endColumn)
                ->where($definitionsTable.'.'.$endColumn, '<', $cutoff));
        $deleted = 0;

        $expiredLinks->chunkById(100, function ($links) use ($pivot, $foreignKey, $definitionsTable, $endColumn, $definitionId, $cutoff, &$deleted): void {
            $deleted += DB::table($pivot)
                ->whereIn('id', $links->pluck('id'))
                ->where($foreignKey, $definitionId)
                ->whereExists(fn (Builder $query): Builder => $query->selectRaw('1')
                    ->from($definitionsTable)
                    ->whereColumn($definitionsTable.'.id', $pivot.'.'.$foreignKey)
                    ->whereNotNull($definitionsTable.'.'.$endColumn)
                    ->where($definitionsTable.'.'.$endColumn, '<', $cutoff))
                ->delete();
        }, $pivot.'.id');

        return $deleted;
    }
}
