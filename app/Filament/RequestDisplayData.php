<?php

namespace App\Filament;

use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use Closure;

class RequestDisplayData
{
    /**
     * Reuse display reads only; callers must authorize each access before calling this method.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public static function remember(string $key, Closure $read): mixed
    {
        $boundary = RequestAuthorizationFacts::readBoundary();
        if ($boundary === null) {
            return $read();
        }
        $actor = auth()->user();
        $attribute = 'filament.display-data.'.hash('sha256', json_encode([
            $boundary, $actor?->getMorphClass(), $actor?->getKey(), $key,
        ], JSON_THROW_ON_ERROR));
        $request = request();
        if ($request->attributes->has($attribute)) {
            return $request->attributes->get($attribute);
        }
        $value = $read();
        if ($boundary === RequestAuthorizationFacts::readBoundary()) {
            $request->attributes->set($attribute, $value);
        }

        return $value;
    }
}
