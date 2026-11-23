<?php

namespace Tests\Unit;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\RecoveryGeneratedExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecoveryGeneratedExpressionTest extends TestCase
{
    #[DataProvider('expressions')]
    public function test_complete_bounded_generated_expression_is_validated(string $expression, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(BackupFailure::class);
            $this->expectExceptionMessage('restore_sql_unsupported');
        }
        (new RecoveryGeneratedExpression)->validate($expression, ['location_id', 'variant_id', 'status', 'pos_device_id']);
        if ($valid) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function expressions(): iterable
    {
        yield 'actual costing' => ['case when `location_id` is null then `variant_id` else NULL end', true];
        yield 'actual POS' => ["case when `status` in ('open','closing') then `pos_device_id` else NULL end", true];
        yield 'nested and parenthesized' => ['(CASE WHEN (`location_id` IS NOT NULL AND `variant_id` >= 1) THEN (CASE WHEN `variant_id` = 2 THEN 3 ELSE 4 END) ELSE NULL END)', true];
        yield 'deterministic arithmetic' => ['(`variant_id` + 2) * 3 % 5', true];
        yield 'multiple branches' => ["CASE WHEN `status` NOT IN ('open') THEN -1 WHEN NOT (`variant_id` = 0) THEN 2 END", true];
        foreach ([
            '', 'CASE WHEN `location_id` IS NULL THEN `variant_id` END junk',
            'CASE WHEN THEN 1 END', 'CASE WHEN 1 THEN END', 'CASE 1 WHEN 1 THEN 2 END',
            'CASE WHEN 1 THEN 2', '`variant_id` +', '(`variant_id`', '`variant_id` `status`',
            'SLEEP(1)', 'BENCHMARK(100,1)', "LOAD_FILE('/tmp/x')", 'ABS(`variant_id`)',
            'JSON_VALID(`status`)', '`evil`(`variant_id`)', '(SELECT 1)', 'EXISTS (SELECT 1)',
            '`other`.`variant_id`', '`db`.`table`.`variant_id`', '`unknown`', '`generated_peer`',
            '@secret', '@@datadir', "1 INTO OUTFILE '/tmp/x'", '1; DROP TABLE `items`',
            '1 /* comment */ + 2', '1 /*!50000 + SLEEP(1) */', '1 -- comment',
            '`variant_id``); DROP TABLE items; --`', 'RAND()', 'CURRENT_TIMESTAMP',
            'CAST(`variant_id` AS CHAR)', '`status` COLLATE utf8mb4_bin', '1 UNION SELECT 2',
            '1 / 2', str_repeat('(', 40).'1'.str_repeat(')', 40),
            implode(' + ', array_fill(0, 300, '1')), str_repeat(' ', 8193),
        ] as $number => $expression) {
            yield 'rejected '.$number => [$expression, false];
        }
    }
}
