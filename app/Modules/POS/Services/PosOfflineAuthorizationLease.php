<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class PosOfflineAuthorizationLease
{
    private const PERMISSIONS = [
        PosPermissions::RETURNS,
        SalesPermissions::RETURNS_VIEW,
        SalesPermissions::RETURNS_CREATE,
    ];

    private const LIFETIME_HOURS = 12;

    /**
     * @param  array{branch_id: int, device_id: int, session_id: int, cashier_id: int}  $scope
     * @return array{can_create_returns: bool, expires_at: ?string, token: ?string}
     */
    public function issue(User $actor, array $scope): array
    {
        $allowed = app(PosAccess::class)->can($actor, PosPermissions::RETURNS)
            && app(SalesAccess::class)->allows($actor, SalesPermissions::RETURNS_VIEW, $scope['branch_id'])
            && app(SalesAccess::class)->allows($actor, SalesPermissions::RETURNS_CREATE, $scope['branch_id']);

        if (! $allowed) {
            return ['can_create_returns' => false, 'expires_at' => null, 'token' => null];
        }

        $expiresAt = now()->addHours(self::LIFETIME_HOURS);
        $claims = [
            'actor_id' => (int) $actor->id,
            'scope' => $scope,
            'permissions' => self::PERMISSIONS,
            'expires_at' => $expiresAt->timestamp,
        ];

        return [
            'can_create_returns' => true,
            'expires_at' => $expiresAt->toIso8601String(),
            'token' => Crypt::encryptString(json_encode($claims, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    public function authorize(User $actor, array $scope, string $token): void
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(403, 'The offline return authorization is invalid.');
        }

        $scopeFields = ['branch_id', 'device_id', 'session_id', 'cashier_id'];
        $authorizedScope = is_array($claims) && is_array($claims['scope'] ?? null)
            ? array_map(fn (string $field): int => (int) ($claims['scope'][$field] ?? 0), $scopeFields)
            : [];
        $requestedScope = array_map(fn (string $field): int => (int) ($scope[$field] ?? 0), $scopeFields);

        abort_unless(is_array($claims)
            && ($claims['actor_id'] ?? null) === (int) $actor->id
            && $authorizedScope === $requestedScope
            && ($claims['permissions'] ?? null) === self::PERMISSIONS
            && is_int($claims['expires_at'] ?? null)
            && $claims['expires_at'] > now()->timestamp, 403, 'The offline return authorization has expired or changed.');

        app(PosAccess::class)->authorize($actor, PosPermissions::RETURNS, (int) $scope['branch_id']);
        app(SalesAccess::class)->authorize($actor, SalesPermissions::RETURNS_VIEW, (int) $scope['branch_id']);
        app(SalesAccess::class)->authorize($actor, SalesPermissions::RETURNS_CREATE, (int) $scope['branch_id']);
    }
}
