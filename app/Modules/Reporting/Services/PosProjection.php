<?php

namespace App\Modules\Reporting\Services;

class PosProjection extends CashProjection
{
    public string $domain = 'pos';

    protected array $keys = ['session_id', 'cashier_id', 'device_id', 'currency'];

    protected array $amounts = PosReportingEvidence::FIELDS;

    protected function rows(int $scope, string $date): array
    {
        return app(PosReportingEvidence::class)->daily($scope, $date)->get()->map(fn ($row): array => (array) $row)->all();
    }
}
