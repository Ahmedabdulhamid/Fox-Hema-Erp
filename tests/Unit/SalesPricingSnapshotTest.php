<?php

namespace Tests\Unit;

use App\Modules\Sales\Services\SalesPricingSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalesPricingSnapshotTest extends TestCase
{
    public function test_object_keys_are_normalized_recursively_without_rewriting_values(): void
    {
        $stored = ['taxes' => [['rate' => '14.0000', 'id' => 2]], 'version' => 1, 'discount' => null];
        $calculated = ['discount' => null, 'version' => 1, 'taxes' => [['id' => 2, 'rate' => '14.0000']]];
        $this->assertSame(SalesPricingSnapshot::normalize($stored), SalesPricingSnapshot::normalize($calculated));
        $this->assertSame(['taxes', 'version', 'discount'], array_keys($stored));
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function distinctEvidence(): array
    {
        return ['list order' => [['taxes' => [1, 2]], ['taxes' => [2, 1]]],
            'numeric type' => [['version' => 1], ['version' => '1']],
            'boolean type' => [['included' => false], ['included' => 'false']],
            'missing snapshot' => [null, []], 'missing key' => [[], ['discount' => null]],
            'decimal precision' => [['amount' => '1.0000'], ['amount' => '1.0001']]];
    }

    #[DataProvider('distinctEvidence')]
    public function test_normalization_does_not_equate_distinct_evidence(mixed $stored, mixed $calculated): void
    {
        $this->assertNotSame(SalesPricingSnapshot::normalize($stored), SalesPricingSnapshot::normalize($calculated));
    }
}
