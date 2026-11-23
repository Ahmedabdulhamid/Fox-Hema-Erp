<?php

namespace App\Modules\Products\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use OverflowException;

class ShortCodeGenerator
{
    private const TABLES = [
        'CAT' => 'categories',
        'TAX' => 'taxes',
        'DIS' => 'discounts',
    ];

    public function next(string $prefix, Model $model): string
    {
        $table = self::TABLES[$prefix] ?? null;
        if ($table === null || $model->getTable() !== $table) {
            throw new LogicException('A supported model and short-code prefix are required.');
        }

        return DB::transaction(function () use ($prefix, $table): string {
            DB::table('short_code_sequences')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0]);
            $sequence = DB::table('short_code_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
            if ($sequence === null) {
                throw new LogicException('The short-code sequence could not be initialized.');
            }

            $next = (int) $sequence->last_number;
            do {
                if ($next >= PHP_INT_MAX) {
                    throw new OverflowException('The short-code sequence is exhausted.');
                }
                $code = $prefix.'-'.str_pad((string) ++$next, 4, '0', STR_PAD_LEFT);
            } while (DB::table($table)->where('code', $code)->exists());

            DB::table('short_code_sequences')->where('prefix', $prefix)->update(['last_number' => $next]);

            return $code;
        }, 3);
    }
}
