<?php

namespace App\Modules\Reporting\Services;

class TreasuryProjection extends CashProjection
{
    public string $domain = 'treasury';

    protected array $keys = ['currency', 'category', 'direction'];

    protected array $amounts = ['amount', 'entry_count', 'reversal_count'];

    protected function rows(int $scope, string $date): array
    {
        return app(TreasuryReportingEvidence::class)->daily($scope, $date)->get()->map(fn ($row): array => (array) $row)->all();
    }
}
