<?php

namespace Tests;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\SuperAdminProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function provisionTestSuperAdmin(): User
    {
        $existing = User::where('email', 'admin@example.test')->exists();

        return app(SuperAdminProvisioner::class)->provision(
            'admin@example.test', $existing, config('access-management.seed_admin.name', 'Super Admin'), 'a-secure-test-password',
        );
    }
}
