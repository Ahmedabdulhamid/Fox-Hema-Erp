<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Services\BackupArchive;
use App\Modules\BackupRestore\Services\BackupEncryption;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupLease;
use App\Modules\BackupRestore\Services\BackupWorkspace;
use App\Modules\BackupRestore\Services\PrivateFiles;
use App\Modules\BackupRestore\Services\RecoveryDatabase;
use App\Modules\BackupRestore\Services\RecoverySql;
use App\Modules\BackupRestore\Services\RecoveryWorkspace;
use App\Modules\BackupRestore\Services\RestoreVerifier;
use App\Modules\BackupRestore\Services\TrustedTreasuryTriggers;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\POS\Services\PosOfflineSaleSyncService;
use App\Modules\POS\Services\PosShiftService;
use App\Modules\POS\Services\PosTerminalCatalog;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Concerns\BackupFileEnvironment;
use Tests\TestCase;

class FullSchemaRecoveryTest extends TestCase
{
    use BackupFileEnvironment;

    /** @var array{host: string, port: int, username: string, password: string} */
    private array $settings = ['host' => '127.0.0.1', 'port' => 33316, 'username' => 'root', 'password' => ''];

    private ?RecoveryWorkspace $recoveryWorkspace = null;

    private ?string $recoveryOwner = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        $this->assertSame(33316, (int) DB::selectOne('SELECT @@port AS port')->port);
        $this->assertSame([], DB::connection()->getSchemaBuilder()->getTableListing(DB::connection()->getDatabaseName(), false));
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->app->forgetInstance(DocumentNumberService::class);
        $this->setUpBackupFiles();
        config(['backup-restore.restore.root' => $this->backupTestRoot.'/recovery', 'backup-restore.release_id' => app(TrustedTreasuryTriggers::class)->releaseId()]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->recoveryWorkspace !== null) {
                $admin = app(RecoveryDatabase::class)->administrator($this->settings);
                $target = 'fh_recovery_'.$this->recoveryWorkspace->operation;
                $exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
                $exists->execute([$target]);
                if ((int) $exists->fetchColumn() > 0) {
                    app(RecoveryDatabase::class)->cleanup($this->settings, $target, $this->recoveryWorkspace, $this->recoveryOwner);
                }
            }
            if (isset($this->backupTestRoot)) {
                $this->removeBackupTestDirectory($this->backupTestRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_complete_application_tables_synthetic_rows_archive_and_independent_cli_round_trip(): void
    {
        $started = microtime(true);
        $source = DB::connection()->getDatabaseName();
        $applicationTables = DB::connection()->getSchemaBuilder()->getTableListing($source, false);
        sort($applicationTables);
        $this->assertCount(136, $applicationTables);
        $this->assertNotContains('definition_history_test', $applicationTables);
        $this->populateBusinessRecords();
        DB::statement('CREATE TABLE phase6c2_generated_probe (`id` int PRIMARY KEY, `amount` decimal(18,4), `virtual_amount` decimal(18,4) GENERATED ALWAYS AS ((`amount` + 1)) VIRTUAL, `persistent_amount` decimal(18,4) GENERATED ALWAYS AS ((`amount` * 2)) PERSISTENT) ENGINE=InnoDB');
        DB::table('phase6c2_generated_probe')->insert(['id' => 1, 'amount' => '12.3456']);
        $tables = [...$applicationTables, 'phase6c2_generated_probe'];
        sort($tables);
        $sourcePdo = DB::connection()->getPdo();
        $sourcePdo->exec("SET SESSION time_zone='+00:00'");
        $before = $this->snapshot($sourcePdo, $tables);
        foreach (['products', 'product_variants', 'locations', 'stock_balances', 'stock_movements', 'inventory_cost_states', 'purchase_orders', 'purchase_receipts', 'sales_invoices', 'sales_returns', 'treasury_entries', 'pos_transactions'] as $table) {
            $this->assertGreaterThan(0, $before[$table]['count'], $table);
        }
        $generated = $this->generated($sourcePdo);
        $this->assertCount(6, $generated);
        foreach (DB::table('inventory_cost_states')->get() as $cost) {
            $this->assertSame($cost->location_id === null ? $cost->variant_id : null, $cost->legacy_variant_id);
        }
        foreach (DB::table('pos_sessions')->get() as $session) {
            $this->assertSame($session->pos_device_id, $session->active_device_id);
            $this->assertSame($session->opened_by, $session->active_cashier_id);
            $this->assertSame($session->cash_treasury_account_id, $session->active_cash_account_id);
        }
        $this->assertSame(['12.3456', '13.3456', '24.6912'], array_values((array) DB::table('phase6c2_generated_probe')->select('amount', 'virtual_amount', 'persistent_amount')->first()));
        $schemaChecksum = app(RestoreVerifier::class)->schemaChecksum($sourcePdo);
        $metadata = $this->metadata($sourcePdo);
        $run = (string) Str::uuid();
        $attempt = (string) Str::uuid();
        $workspace = BackupWorkspace::forLease(new BackupLease($run, bin2hex(random_bytes(32)), 1));

        $dump = $workspace->file('complete-native.sql');
        $this->dump($source, $dump, false);
        $catalog = app(TrustedTreasuryTriggers::class);
        $profile = $catalog->profile($sourcePdo);
        $triggers = $sourcePdo->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(8, $triggers);
        $catalog->prepare($dump, $workspace->file('trusted-tables.sql'), $profile, static fn () => null);
        try {
            $counts = app(RecoverySql::class)->prepare($workspace->file('trusted-tables.sql'), $workspace->file('validated-tables.sql'), static fn () => null);
        } catch (BackupFailure $failure) {
            preg_match_all('/CREATE TABLE `([^`]+)`/', file_get_contents($dump), $all);
            preg_match_all('/CREATE TABLE `([^`]+)`/', file_get_contents($workspace->file('validated-tables.sql')), $validated);
            $remaining = array_values(array_diff($all[1], $validated[1]));
            file_put_contents(storage_path('framework/testing/phase6c2/unsupported-synthetic-dump.sql'), file_get_contents($dump));
            $this->fail('Table grammar rejected: '.($remaining[0] ?? 'after table definitions').' / '.$failure->getMessage());
        }
        $this->assertSame(array_map(fn (array $row): int => $row['count'], $before), $counts);
        $files = [];
        foreach (['database/database.sql' => $dump, 'files/public/products/schema.png' => 'synthetic-schema-image'] as $path => $contents) {
            $file = $path === 'database/database.sql' ? $dump : $workspace->file('media.txt');
            if ($file !== $dump) {
                file_put_contents($file, $contents);
            }
            $files[] = ['path' => $path, 'source' => $file, 'size' => filesize($file), 'sha256' => hash_file('sha256', $file), 'mtime' => 1, 'mode' => 0600];
        }
        $migrations = $sourcePdo->query('SELECT migration,batch FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_OBJ);
        $archive = app(BackupArchive::class)->build($workspace, $files, ['run_uuid' => $run, 'attempt_uuid' => $attempt, 'fence' => 1,
            'release_id' => config('backup-restore.release_id'), 'composer_lock_checksum' => hash_file('sha256', base_path('composer.lock')),
            'consistency_mode' => 'isolated_test', 'database' => ['database' => $source, 'schema_checksum' => $schemaChecksum, 'migrations' => $migrations, 'row_counts' => $counts, 'trigger_profile' => $profile]], static fn () => null);
        $encrypted = $workspace->file('artifact.fhbk');
        app(BackupEncryption::class)->encrypt($archive['path'], $encrypted, 'v1', ['run_uuid' => $run, 'attempt_uuid' => $attempt, 'fence' => 1, 'manifest_checksum' => $archive['manifest_checksum']], static fn () => null);
        $operation = bin2hex(random_bytes(16));
        $kit = ['endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'bucket' => 'synthetic-only', 'prefix' => 'synthetic',
            'operation' => $operation, 'target' => 'fh_recovery_'.$operation, 'root' => config('backup-restore.restore.root'), 'key_id' => 'v1',
            'key_file' => $this->backupKeyPath, 'release_id' => config('backup-restore.release_id'), 'client_binary' => 'C:/xampp/mysql/bin/mysql.exe', 'database' => $this->settings];
        $object = 'runs/'.$run.'/attempt-'.$attempt.'.fhbk';
        $identity = ['run_uuid' => $run, 'attempt_uuid' => $attempt, 'fence' => 1, 'key_id' => 'v1', 'release_id' => $kit['release_id'], 'source_database' => $source,
            'schema_checksum' => $schemaChecksum, 'manifest_checksum' => $archive['manifest_checksum'], 'ciphertext_checksum' => hash_file('sha256', $encrypted), 'size_bytes' => filesize($encrypted), 'file_count' => count($files)];
        $descriptor = ['endpoint' => $kit['endpoint'], 'bucket' => $kit['bucket'], 'prefix' => $kit['prefix'], 'object_key' => $object, 'identity' => $identity];
        $storage = Storage::fake('phase6c2-objects');
        $storage->put('synthetic/'.$object, file_get_contents($encrypted));
        $offline = $workspace->file('offline.fhbk');
        $stream = app(PrivateFiles::class)->create($offline);
        PrivateFiles::write($stream, $storage->get('synthetic/'.$object));
        fclose($stream);
        foreach (['kit' => $kit, 'descriptor' => $descriptor] as $name => $data) {
            $stream = app(PrivateFiles::class)->create($workspace->file($name.'.json'));
            PrivateFiles::write($stream, json_encode($data, JSON_THROW_ON_ERROR));
            fclose($stream);
        }
        $cli = new Process([PHP_BINARY, base_path('app/Modules/BackupRestore/Console/recover.php'), '--kit='.$workspace->file('kit.json'),
            '--descriptor='.$workspace->file('descriptor.json'), '--artifact='.$offline, '--approve-isolated'], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'unavailable_original_database', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'unavailable_original_database']);
        try {
            $cli->setTimeout(120)->run();
        } finally {
            $ownerPath = $kit['root'].'/'.$operation.'/owner.json';
            if (is_file($ownerPath)) {
                $this->recoveryOwner = json_decode(file_get_contents($ownerPath), true, flags: JSON_THROW_ON_ERROR)['owner'];
                $this->recoveryWorkspace = new RecoveryWorkspace(new BackupWorkspace(dirname($ownerPath)), $operation, $this->recoveryOwner);
            }
            $storage->deleteDirectory('synthetic');
        }
        $restored = app(RecoveryDatabase::class)->administrator($this->settings);
        $restored->exec('USE `'.$kit['target'].'`');
        $this->assertSame($before, $this->snapshot($restored, $tables));
        $this->assertSame($metadata, $this->metadata($restored));
        $this->assertSame($generated, $this->generated($restored));
        $this->assertSame($schemaChecksum, app(RestoreVerifier::class)->schemaChecksum($restored));
        $stages = [];
        foreach (file($kit['root'].'/'.$operation.'/journal.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
            $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $stages[] = ['stage' => $entry['stage'], 'check' => $entry['facts']['check'] ?? null];
        }
        file_put_contents(storage_path('framework/testing/phase6c2/full-schema-verifier-stages.json'), json_encode($stages, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->assertSame(0, $cli->getExitCode(), $cli->getErrorOutput());
        $result = json_decode($cli->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['archive_integrity_verified']);
        $this->assertTrue($result['isolated_restoration_verified']);
        $this->assertFalse($result['production_recovery_ready']);
        $this->assertSame($files[1]['sha256'], hash_file('sha256', $kit['root'].'/'.$operation.'/recovered/'.$files[1]['path']));
        $this->assertSame($before, $this->snapshot($sourcePdo, $tables));
        $this->assertSame(8, $result['trusted_triggers_verified']);
        $catalog->assertInventory($catalog->inventory($restored), app(RecoveryDatabase::class)->triggerDefiner($this->recoveryOwner).'@127.0.0.1');
        $invariants = TrustedTriggerRecoveryTest::invariantResults($restored);
        $this->assertSame(TrustedTriggerRecoveryTest::invariantResults($sourcePdo), $invariants);
        foreach ($invariants as $invariant) {
            $this->assertSame(['45000', 1644], array_slice($invariant, 0, 2));
        }
        $this->assertSame($before, $this->snapshot($restored, $tables));
        $summary = ['application_tables' => count($applicationTables), 'additional_generated_probe_tables' => 1, 'imported_tables' => count($tables), 'rows' => array_sum($counts),
            'table_names' => $tables, 'table_row_counts' => $counts, 'generated_columns' => $generated, 'table_schema_and_rows_equal' => true, 'indexes_constraints_foreign_keys_equal' => true,
            'generated_values_and_decimals_equal' => true, 'migrations_equal' => true, 'media_hashes_equal' => true, 'archive_and_independent_cli_verified' => true,
            'original_application_database_unavailable' => true, 'full_native_dump_with_triggers' => 'verified', 'source_triggers' => $triggers,
            'restored_triggers' => 8, 'complete_schema_including_triggers_verified' => true, 'financial_invariants_verified' => count($invariants), 'trigger_profile' => $profile, 'full_backup_engine' => 'blocked_not_bypassed', 'real_r2_operations' => false,
            'verifier_findings' => $result, 'elapsed_seconds' => round(microtime(true) - $started, 3)];
        file_put_contents(storage_path('framework/testing/phase6c2/full-schema-recovery.json'), json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function populateBusinessRecords(): void
    {
        app()->setLocale('en');
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        $actor = $this->provisionTestSuperAdmin();
        $this->actingAs($actor);
        setPermissionsTeamId(null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $branch = Location::create(['name' => 'Synthetic recovery branch', 'type' => 'branch', 'is_active' => true]);
        Location::create(['name' => 'Synthetic recovery warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $actor->locations()->attach($branch);
        app(LocationContext::class)->setFor($actor, (int) $branch->id);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true, 'has_fixed_selling_price' => true, 'sale_price' => '100']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        ProductImage::create(['product_id' => $product->id, 'disk' => 'public', 'path' => 'products/schema.png', 'sort_order' => 0]);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($actor, ['supplier_id' => Supplier::factory()->create()->id, 'location_id' => $branch->id, 'order_date' => today()->toDateString(),
            'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($variant)['receiving_mode'], 'quantity' => '10', 'unit_price' => '20.1234']]], (string) Str::uuid7());
        $order = $orders->confirm($actor, $order, $orders->revision($order));
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id, 'quantity' => '10', 'unit_cost' => '20.1234']]], $receipts->revision($receipt));
        $receipts->post($actor, $receipt, $receipts->revision($receipt));
        $sales = app(SalesOrderService::class);
        $sale = $sales->create($actor, ['location_id' => $branch->id, 'customer_name' => 'Synthetic schema customer', 'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'description' => 'Synthetic item', 'quantity' => '2', 'unit_price' => '100']]], (string) Str::uuid7());
        $sale = app(SalesOrderConfirmationService::class)->confirm($actor, $sale);
        $deliveries = app(SalesDeliveryService::class);
        $delivery = $deliveries->create($actor, ['sales_order_id' => $sale->id, 'items' => [['sales_order_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => '2']]], (string) Str::uuid7());
        $delivery = app(SalesDeliveryPostingService::class)->post($actor, $delivery, $deliveries->revision($delivery));
        $invoices = app(SalesInvoiceService::class);
        $invoice = $invoices->create($actor, ['sales_order_id' => $sale->id, 'invoice_date' => today()->toDateString(), 'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '2']]], (string) Str::uuid7());
        $invoices->post($actor, $invoice, $invoices->revision($invoice));
        app(SalesReturnService::class)->create($actor, ['sales_order_id' => $sale->id, 'return_date' => today()->toDateString(), 'reason' => 'Synthetic schema return',
            'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '1', 'disposition' => 'restock']]], (string) Str::uuid7());
        $cash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $actor->id]);
        $treasury = app(TreasuryService::class);
        $draft = $treasury->createDraft($actor, ['type' => 'cash_deposit', 'account_id' => $cash->id, 'amount' => '12.3456', 'effective_date' => today()->toDateString(),
            'source_type' => 'external_capital', 'source_reference' => 'SYN-'.Str::uuid7(), 'reason' => 'Synthetic schema deposit'], (string) Str::uuid7());
        $treasury->post($actor, $draft);
        $treasury->createDraft($actor, ['type' => 'cash_deposit', 'account_id' => $cash->id, 'amount' => '1.0000', 'effective_date' => today()->toDateString(),
            'source_type' => 'external_capital', 'source_reference' => 'SYN-DRAFT-'.Str::uuid7(), 'reason' => 'Synthetic immutable code probe'], (string) Str::uuid7());
        $posCash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $actor->id]);
        $method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false, 'available_in_pos' => true]);
        $devices = app(PosDeviceManagement::class);
        $device = $devices->register($actor, ['name' => 'Synthetic recovery register', 'location_id' => $branch->id]);
        $devices->configurePaymentMethod($actor, $device, (int) $method->id, (int) $posCash->id);
        $shift = app(PosShiftService::class)->open($actor, (int) $device->id, (int) $posCash->id, '0', (string) Str::uuid7());
        $quote = app(PosTerminalCatalog::class)->quote($actor, (int) $branch->id, [$variant->id => ['quantity' => '1', 'discount_id' => null]]);
        $quote['items'] = array_map(fn (array $item): array => array_intersect_key($item, array_flip(['variant_id', 'quantity', 'discount_id', 'unit_price', 'subtotal', 'discount_amount', 'tax_amount', 'line_total'])), $quote['items']);
        $response = app(PosOfflineSaleSyncService::class)->synchronize($actor, $device, ['client_transaction_id' => (string) Str::uuid7(),
            'scope' => ['branch_id' => (int) $branch->id, 'device_id' => (int) $device->id, 'session_id' => (int) $shift->id, 'cashier_id' => (int) $actor->id],
            'sale' => ['items' => [['variant_id' => $variant->id, 'quantity' => '1']], 'tenders' => [['payment_method_id' => $method->id, 'amount' => '100']], 'cash_received' => '100'], 'quote' => $quote]);
        $this->assertSame('completed', $response['status']);
        DB::table('inventory_cost_states')->insert(['variant_id' => $variant->id, 'location_id' => null, 'avg_cost' => '20.123456', 'on_hand_qty' => '0.1250', 'total_value' => '2.5154']);
    }

    private function dump(string $source, string $path, bool $tablesOnly): void
    {
        $arguments = ['C:/xampp/mysql/bin/mysqldump.exe', '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', '--port=33316', '--user=root', '--single-transaction', '--skip-lock-tables', '--hex-blob', '--tz-utc', '--routines', '--events'];
        if ($tablesOnly) {
            $arguments[] = '--skip-triggers';
        }
        (new Process([...$arguments, '--result-file='.$path, $source]))->setTimeout(45)->mustRun();
    }

    /** @param list<string> $tables
     * @return array<string, array{count: int, digest: string}>
     */
    private function snapshot(PDO $database, array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $rows = $database->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $encoded = array_map(serialize(...), $rows);
            sort($encoded);
            $result[$table] = ['count' => count($rows), 'digest' => hash('sha256', serialize($encoded))];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function generated(PDO $database): array
    {
        return $database->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION <> '' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function metadata(PDO $database): array
    {
        return [
            'tables' => $database->query("SELECT TABLE_NAME,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME <> '__fh_recovery_owner' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC),
            'checks' => $database->query('SELECT TABLE_NAME,CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'keys' => $database->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME <> '__fh_recovery_owner' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
