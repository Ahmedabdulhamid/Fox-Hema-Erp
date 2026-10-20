<?php

namespace App\Modules\AccessManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\LocationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LocationContextController extends Controller
{
    public function __invoke(Request $request, LocationContext $context): RedirectResponse
    {
        $data = $request->validate(['location_id' => ['required', 'regex:/^(global|[1-9][0-9]*)$/']]);
        $user = $request->user();
        abort_unless($user->is_active, 403);
        $previous = $context->initializeFor($user);
        $locationId = $data['location_id'] === 'global' ? null : (int) $data['location_id'];
        $context->setFor($user, $locationId);
        if (! $user->hasPanelPermissions()) {
            $context->setFor($user, $previous);
            throw ValidationException::withMessages(['location_id' => __('You have no panel access permissions in this location.')]);
        }
        app(AuditLogger::class)->record('location_switched', $user, ['location_id' => $locationId]);

        return redirect()->route('filament.admin.pages.dashboard');
    }
}
