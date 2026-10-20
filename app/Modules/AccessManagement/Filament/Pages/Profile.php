<?php

namespace App\Modules\AccessManagement\Filament\Pages;

use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\DatabaseSession;
use Filament\Auth\Pages\EditProfile;
use Illuminate\Database\Eloquent\Collection;

class Profile extends EditProfile
{
    protected string $view = 'access-management::filament.pages.profile';

    public static function getLabel(): string
    {
        return __('Profile');
    }

    public function initials(): string
    {
        return str($this->getUser()->name)->explode(' ')->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }

    public function sessions(): Collection
    {
        return DatabaseSession::query()->where('user_id', $this->getUser()->getKey())->latest('last_activity')->limit(5)->get();
    }

    public function securityEvents(): Collection
    {
        return AuthEvent::query()->where('target_user_id', $this->getUser()->getKey())->latest('occurred_at')->limit(6)->get();
    }
}
