<?php

namespace App\Http\Controllers;

use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Services\PosOfflineOutboxes;
use App\Modules\POS\Services\PosOfflineReturnSyncService;
use App\Modules\POS\Services\PosOfflineSaleSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosOfflineSyncController extends Controller
{
    public function outbox(Request $request, PosDevice $device, PosOfflineOutboxes $outboxes): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 524288, 413);
        app(LocationContext::class)->initializeFor($request->user());
        $data = $request->validate([
            'action' => ['required', 'in:register,verify'],
            'outbox_id' => ['required', 'uuid', 'lowercase'],
            'scope' => ['required', 'array'],
        ]);
        if ($data['action'] === 'verify') {
            return response()->json($outboxes->verify($request->user(), $device, $data['scope'], $data['outbox_id']),
                200, ['Cache-Control' => 'private, no-store']);
        } elseif ($data['action'] === 'register') {
            $outboxes->register($request->user(), $device, $data['scope'], $data['outbox_id']);
        }

        return response()->json(['outbox_id' => $data['outbox_id'], 'status' => 'registered'],
            200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, PosDevice $device, PosOfflineSaleSyncService $sync): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 524288, 413);
        app(LocationContext::class)->initializeFor($request->user());
        $acknowledgement = $sync->synchronize($request->user(), $device, $request->all());

        return response()->json($acknowledgement, 200, ['Cache-Control' => 'private, no-store']);
    }

    public function syncReturn(Request $request, PosDevice $device, PosOfflineReturnSyncService $sync): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 524288, 413);
        app(LocationContext::class)->initializeFor($request->user());
        $acknowledgement = $sync->synchronize($request->user(), $device, $request->all());

        return response()->json($acknowledgement, 200, ['Cache-Control' => 'private, no-store']);
    }
}
