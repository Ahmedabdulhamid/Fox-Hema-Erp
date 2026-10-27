<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Data\PostedPaymentReference;

interface TreasuryPaymentGateway
{
    /** Implementations must deduplicate by payment UUID and return durable movement evidence. */
    public function record(PostedPaymentReference $payment): string;
}
