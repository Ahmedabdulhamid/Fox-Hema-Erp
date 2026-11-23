<?php

namespace App\Modules\POS\Database\Seeders;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Services\PosShiftService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ramsey\Uuid\Uuid;

class PosSessionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new LogicException('POS demo sessions can only be seeded in the local environment.');
        }

        $this->call(PosDatabaseSeeder::class);
        $actor = User::query()->whereKey(config('treasury.seed_actor_id'))->where('is_active', true)->firstOrFail();
        $originalLocationId = getPermissionsTeamId();

        try {
            DB::transaction(function () use ($actor): void {
                ModuleRegistryLock::acquire();
                if (PosSession::query()->where('opened_by', $actor->id)->whereIn('status', ['open', 'closing'])->exists()) {
                    $this->command?->info('The seed cashier already has an active session. Existing sessions were preserved.');

                    return;
                }

                $service = app(PosShiftService::class);
                $availableDevice = null;
                $availableDrawerId = null;
                $availableOpeningCash = null;

                foreach (PosDevice::query()->where('is_active', true)->whereNull('revoked_at')
                    ->whereHas('location', fn ($query) => $query->where('type', 'branch')->where('is_active', true))
                    ->orderBy('id')->get() as $device) {
                    setPermissionsTeamId((int) $device->location_id);
                    $drawerId = array_key_first($service->cashDrawerOptions($actor, (int) $device->id));
                    if ($drawerId === null || $device->sessions()->whereIn('status', ['open', 'closing'])->exists()) {
                        continue;
                    }
                    $openingCash = app(TreasuryService::class)->balance($actor, TreasuryAccount::findOrFail($drawerId));

                    $openingKey = Uuid::uuid5(Uuid::NAMESPACE_URL, 'fox-hema/pos/demo/closed/'.$device->uuid)->toString();
                    if (! PosSession::query()->where('opening_key', $openingKey)->exists()) {
                        $session = $service->open($actor, (int) $device->id, $drawerId, $openingCash, $openingKey, 'Demo closed POS session');
                        $service->close($actor, (int) $session->id, [
                            'counted_cash' => $openingCash,
                            'tender_counts' => [],
                        ], Uuid::uuid5(Uuid::NAMESPACE_URL, 'fox-hema/pos/demo/close/'.$device->uuid)->toString());
                    }

                    if ($availableDevice === null) {
                        $availableDevice = $device;
                        $availableDrawerId = $drawerId;
                        $availableOpeningCash = $openingCash;
                    }
                }

                if ($availableDevice !== null) {
                    $openingKey = Uuid::uuid5(Uuid::NAMESPACE_URL, 'fox-hema/pos/demo/open/'.$availableDevice->uuid)->toString();
                    if (! PosSession::query()->where('opening_key', $openingKey)->exists()) {
                        setPermissionsTeamId((int) $availableDevice->location_id);
                        $service->open($actor, (int) $availableDevice->id, $availableDrawerId, $availableOpeningCash, $openingKey, 'Demo open POS session');
                    }
                }
            }, 3);
        } finally {
            setPermissionsTeamId($originalLocationId);
        }

        $this->command?->info('POS demo sessions are ready. Opening cash matches Treasury; no sales or Treasury entries were created.');
    }
}
