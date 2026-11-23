<?php

namespace Tests\Feature;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\Category;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\DocumentTestSetup;
use Tests\TestCase;

class ShortCodeGenerationTest extends TestCase
{
    use DocumentTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_observers_generate_sequential_codes_for_all_definition_models(): void
    {
        $category = Category::create(['name' => 'Observer category']);
        $tax = Tax::create(['name' => 'Observer tax']);
        $discount = Discount::create(['name' => 'Observer discount', 'type' => 'percentage', 'value' => '10.0000']);

        $this->assertSame('CAT-0001', $category->code);
        $this->assertSame('TAX-0001', $tax->code);
        $this->assertSame('DIS-0001', $discount->code);
    }

    public function test_concurrent_category_creations_receive_distinct_sequential_codes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent short-code allocation requires MariaDB.');
        }

        $this->assertTrue(str_starts_with(DB::connection()->getDatabaseName(), 'codex_public_ids_test_'));
        $configuration = config('database.connections.mysql');
        $workers = [];
        $start = microtime(true) + 2;

        try {
            foreach (range(1, 6) as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/ShortCodeWorker.php')], base_path(), [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'mysql',
                    'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'DB_HOST' => $configuration['host'],
                    'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'],
                    'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '',
                    'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array',
                    'SESSION_DRIVER' => 'array',
                    'QUEUE_CONNECTION' => 'sync',
                    'SHORT_CODE_START' => (string) $start,
                    'SHORT_CODE_NAME' => (string) $worker,
                ]);
                $process->setTimeout(90)->start();
                $workers[] = $process;
            }

            $codes = [];
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $codes[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['code'];
            }

            sort($codes);
            $this->assertSame(['CAT-0001', 'CAT-0002', 'CAT-0003', 'CAT-0004', 'CAT-0005', 'CAT-0006'], $codes);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
