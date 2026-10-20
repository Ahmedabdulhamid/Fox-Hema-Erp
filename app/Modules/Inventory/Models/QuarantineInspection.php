<?php

namespace App\Modules\Inventory\Models;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\ImmutableReturnEvidence;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuarantineInspection extends ImmutableReturnEvidence
{
    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by')->withTrashed();
    }
}
