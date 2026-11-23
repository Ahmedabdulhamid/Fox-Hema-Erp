<?php

namespace Tests\Unit;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\CgroupProcessFence;
use PHPUnit\Framework\TestCase;

class CgroupProcessFenceTest extends TestCase
{
    public function test_process_tree_is_killed_and_remains_frozen_across_watchdog_restart(): void
    {
        $fixture = $this->fixture();
        $this->assertTrue($fixture->stop($this->barrier()));
        $this->assertSame(['cgroup.freeze' => '1', 'cgroup.kill' => '1'], $fixture->writes);
        $this->assertTrue($this->fixture()->stop($this->barrier()));
    }

    public function test_expired_identity_wrong_boot_and_recreated_group_are_not_death_proof(): void
    {
        $barrier = $this->barrier();
        $barrier->process_identity = null;
        $this->assertFalse($this->fixture()->stop($barrier));
        $barrier = $this->barrier();
        $identity = json_decode($barrier->process_identity, true);
        $identity['boot'] = 'another-boot';
        $barrier->process_identity = json_encode($identity);
        $this->assertFalse($this->fixture()->stop($barrier));
        $identity['boot'] = 'test-boot';
        $identity['inode'] = 100;
        $barrier->process_identity = json_encode($identity);
        $fixture = $this->fixture();
        $this->assertFalse($fixture->stop($barrier));
        $this->assertSame([], $fixture->writes);
    }

    public function test_incomplete_child_termination_or_failed_controller_access_fails_closed(): void
    {
        $fixture = $this->fixture();
        $fixture->events = "populated 1\nfrozen 1\n";
        $fixture->failAfterRead = true;
        $this->assertFalse($fixture->stop($this->barrier()));
        $this->assertSame('1', $fixture->writes['cgroup.freeze']);
        $fixture = $this->fixture();
        $fixture->denyWrites = true;
        $this->assertFalse($fixture->stop($this->barrier()));
    }

    public function test_empty_group_without_persistent_freeze_or_changed_identity_is_not_safe(): void
    {
        $fixture = $this->fixture();
        $fixture->freeze = '0';
        $fixture->failAfterRead = true;
        $this->assertFalse($fixture->stop($this->barrier()));
        $fixture = $this->fixture();
        $fixture->replaceAfterKill = true;
        $this->assertFalse($fixture->stop($this->barrier()));
    }

    public function test_watchdog_cannot_kill_its_own_process_domain_or_accept_path_traversal(): void
    {
        $fixture = $this->fixture();
        $fixture->membership = '0::/erp-backup.slice/capture-11111111-1111-4111-8111-111111111111-1';
        $this->assertFalse($fixture->stop($this->barrier()));
        $barrier = $this->barrier();
        $barrier->run_uuid = '../system.slice';
        $this->assertFalse($this->fixture()->stop($barrier));
    }

    public function test_unsupported_platform_never_treats_a_missing_pid_as_proof(): void
    {
        $adapter = new CgroupProcessFence;
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->assertNull($adapter->identity('11111111-1111-4111-8111-111111111111', 1));
        }
        $this->assertFalse($adapter->stop((object) ['process_identity' => null]));
    }

    private function barrier(): object
    {
        return (object) ['run_uuid' => '11111111-1111-4111-8111-111111111111', 'lease_fence' => 1,
            'process_identity' => json_encode(['boot' => 'test-boot', 'group' => '/sys/fs/cgroup/erp-backup.slice/capture-11111111-1111-4111-8111-111111111111-1', 'device' => 1, 'inode' => 2])];
    }

    private function fixture(): CgroupProcessFence
    {
        return new class extends CgroupProcessFence
        {
            public array $writes = [];

            public string $events = "populated 0\nfrozen 1\n";

            public string $freeze = '1';

            public string $membership = '0::/watchdog.slice';

            public bool $denyWrites = false;

            public bool $failAfterRead = false;

            public bool $replaceAfterKill = false;

            private int $reads = 0;

            protected function supported(bool $controller): bool
            {
                return true;
            }

            protected function inspect(string $group): array
            {
                return ['dev' => 1, 'ino' => $this->replaceAfterKill && $this->writes !== [] ? 3 : 2];
            }

            protected function read(string $path): string
            {
                if ($this->failAfterRead && $this->writes !== [] && $this->reads++ > 1) {
                    throw new BackupFailure('write_barrier_recovery_required');
                }

                return match (basename($path)) {
                    'boot_id' => 'test-boot', 'cgroup' => $this->membership,
                    'cgroup.events' => $this->events, 'cgroup.freeze' => $this->freeze,
                    default => throw new \LogicException('Unexpected fixture read'),
                };
            }

            protected function write(string $path, string $value): void
            {
                if ($this->denyWrites) {
                    throw new BackupFailure('write_barrier_recovery_required');
                }
                $this->writes[basename($path)] = $value;
            }
        };
    }
}
