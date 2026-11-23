<?php

namespace App\Modules\Reporting\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Sales\Services\ProfitabilityReport;

class ProfitabilityAccess extends ReportingAccess
{
    public const VIEW = 'reporting.profitability.view';

    public function authorize(User $actor, ?int $locationId = null): void
    {
        app(ModuleAccess::class)->authorize($actor, 'reporting', self::VIEW);
        abort_unless(app(ProfitabilityReport::class)->canView($actor, $locationId), 403);
    }
}
