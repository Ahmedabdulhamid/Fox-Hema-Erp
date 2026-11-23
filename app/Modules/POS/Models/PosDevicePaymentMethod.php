<?php

namespace App\Modules\POS\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosDevicePaymentMethod extends Model
{
    use HasPublicUuid;

    protected $table = 'pos_device_payment_methods';

    protected $fillable = [
        'uuid',
        'pos_device_id',
        'payment_method_id',
        'treasury_account_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getDisplayNameAttribute(): string
    {
        $deviceCode = $this->posDevice?->device_code;
        $paymentMethodName = $this->paymentMethod?->name;

        return implode(' · ', array_filter([$deviceCode, $paymentMethodName]));
    }

    public function posDevice(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }
}
