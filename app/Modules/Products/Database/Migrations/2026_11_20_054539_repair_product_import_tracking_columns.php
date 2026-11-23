<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missing = array_values(array_filter(['source_rows', 'variant_count', 'image_count'],
            fn (string $column): bool => ! Schema::hasColumn('product_import_entries', $column)));
        if ($missing !== []) {
            Schema::table('product_import_entries', function (Blueprint $table) use ($missing): void {
                foreach ($missing as $column) {
                    $table->unsignedInteger($column)->default(0);
                }
            });
        }
        if (! Schema::hasColumn('product_imports', 'recovery_attempts')) {
            Schema::table('product_imports', function (Blueprint $table): void {
                $table->unsignedInteger('recovery_attempts')->default(0);
            });
        }

        DB::table('product_import_entries')->select('id')->orderBy('id')->chunkById(200, function (Collection $entries): void {
            foreach ($entries as $identity) {
                $entry = DB::table('product_import_entries')->where('id', $identity->id)->first();
                if ($entry === null) {
                    continue;
                }
                $counts = $this->counts($entry);
                foreach ($counts as $column => $count) {
                    if ((int) $entry->{$column} === 0 && $count > 0) {
                        DB::table('product_import_entries')->where('id', $entry->id)->where($column, 0)->update([$column => $count]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Product import tracking repair is forward-only; retained audit counts must not be dropped.');
    }

    /** @return array{source_rows: int, variant_count: int, image_count: int} */
    private function counts(object $entry): array
    {
        $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);
        $counts = [];
        foreach (['Products', 'Variants', 'ProductTaxes', 'ProductDiscounts', 'Images'] as $sheet) {
            $counts[$sheet] = count($payload[$sheet] ?? []);
        }
        if (array_sum($counts) > 0) {
            return ['source_rows' => array_sum($counts), 'variant_count' => $counts['Variants'], 'image_count' => $counts['Images']];
        }

        $rows = DB::table('product_import_rows')->where('import_id', $entry->import_id)->where('product_key', $entry->product_key)
            ->selectRaw("COUNT(*) AS source_rows, COALESCE(SUM(CASE WHEN sheet = 'Variants' THEN 1 ELSE 0 END), 0) AS variant_count, COALESCE(SUM(CASE WHEN sheet = 'Images' THEN 1 ELSE 0 END), 0) AS image_count")->first();
        if ((int) $rows->source_rows > 0) {
            return ['source_rows' => (int) $rows->source_rows, 'variant_count' => (int) $rows->variant_count, 'image_count' => (int) $rows->image_count];
        }

        $result = json_decode($entry->result ?? '[]', true, 512, JSON_THROW_ON_ERROR);

        /** Purged source/assignment rows cannot be inferred from committed variant/image results. */
        return ['source_rows' => 0, 'variant_count' => count($result['variants'] ?? []), 'image_count' => count($result['images'] ?? [])];
    }
};
