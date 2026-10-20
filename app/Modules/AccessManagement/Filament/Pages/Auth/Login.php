<?php

namespace App\Modules\AccessManagement\Filament\Pages\Auth;

use App\Modules\AccessManagement\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected ?string $accessFailure = null;

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        $this->accessFailure = null;
        if (parent::isUserAllowedToAccessPanel($user)) {
            return true;
        }
        if ($user instanceof User) {
            $this->accessFailure = match (true) {
                ! $user->is_active => __('Your account is inactive. Contact your administrator.'),
                ! $user->locations()->where('is_active', true)->exists() => __('No active location is assigned to your account. Contact your administrator.'),
                default => __('Your account has no panel access permissions in its assigned locations. Ask your administrator to review your location roles and permissions.'),
            };
        }

        return false;
    }

    protected function throwFailureValidationException(): never
    {
        if ($this->accessFailure !== null) {
            $message = $this->accessFailure;
            $this->accessFailure = null;
            throw ValidationException::withMessages(['data.email' => $message]);
        }
        parent::throwFailureValidationException();
    }
}
