<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Models\PosDevice;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PosDevicePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->app->make('migrator')->path(app_path('Modules/POS/Database/Migrations/2026_10_11_000012_create_pos_devices_table.php'));
        foreach (glob(app_path('Modules/POS/Database/Migrations/*_add_public_identifiers_to_*_tables.php')) as $migration) {
            $this->app->make('migrator')->path($migration);
        }
    }

    public function test_device_details_and_inverse_relationships_persist_without_mixing_locations(): void
    {
        $actor = User::factory()->create();
        $device = PosDevice::factory()->create([
            'registered_by' => $actor->id,
            'is_active' => false,
            'registered_at' => '2026-10-11 12:00:00',
            'last_seen_at' => '2026-10-11 12:30:00',
            'revoked_at' => '2026-10-11 13:00:00',
        ]);
        PosDevice::factory()->create();
        $device = $device->fresh(['location.posDevices', 'registeredBy.registeredPosDevices']);
        $this->assertFalse($device->is_active);
        $this->assertSame('2026-10-11 12:00:00', $device->registered_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11 12:30:00', $device->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11 13:00:00', $device->revoked_at->format('Y-m-d H:i:s'));
        $this->assertTrue($device->registeredBy->is($actor));
        $this->assertSame([$device->id], $device->location->posDevices->modelKeys());
        $this->assertSame([$device->id], $device->registeredBy->registeredPosDevices->modelKeys());
    }

    public function test_permanently_deleting_the_registering_user_preserves_the_device(): void
    {
        $actor = User::factory()->create();
        $device = PosDevice::factory()->create(['registered_by' => $actor->id]);
        $actor->forceDelete();
        $device = $device->fresh(['registeredBy']);
        $this->assertNotNull($device);
        $this->assertNull($device->registered_by);
        $this->assertNull($device->registeredBy);
    }
}
