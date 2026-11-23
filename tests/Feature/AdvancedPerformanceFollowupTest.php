<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Http\Middleware\SetLocationPermissionContext;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Filament\Resources\Products\Pages\ListProducts;
use App\Modules\Products\Filament\Resources\Products\Pages\ViewProduct;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\ListSalesInvoices;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\Pages\ListTreasuryAccounts;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Closure;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\View\Engine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class AdvancedPerformanceFollowupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reader;

    private Location $branch;

    private Location $foreign;

    private bool $recording = false;

    private string $directory;

    private array $queries = [];

    private array $sources = [];

    private array $views = [];

    private array $viewStack = [];

    private array $policies = [];

    private array $lifecycle = [];

    private ?string $controlDiscovery = null;

    private array $measurements = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        config(['debugbar.enabled' => false, 'app.debug' => true]);
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        app()->setLocale('en');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = $this->provisionTestSuperAdmin();
        $this->admin->forceFill(['must_change_password' => false])->save();
        $this->branch = Location::create(['name' => 'Advanced Branch 1', 'type' => 'branch', 'is_active' => true]);
        $this->foreign = Location::create(['name' => 'Advanced Branch 2', 'type' => 'branch', 'is_active' => true]);
        Location::create(['name' => 'Advanced Branch 3', 'type' => 'branch', 'is_active' => true]);
        Location::create(['name' => 'Advanced Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->reader = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->reader->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->reader->givePermissionTo(Permission::where('guard_name', 'web')->where('name', 'not like', '%all_locations%')->pluck('name')->all());
        $phase = preg_replace('/[^a-zA-Z0-9_-]/', '', getenv('ADVANCED_PROFILE_PHASE') ?: 'current');
        $this->directory = storage_path('framework/testing/advanced-'.$phase);
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
        DB::listen(function (QueryExecuted $event): void {
            if (! $this->recording) {
                return;
            }
            if (! isset($this->sources[$event->sql])) {
                $this->sources[$event->sql] = [];
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 70) as $frame) {
                    $file = str_replace('\\', '/', $frame['file'] ?? '');
                    if (str_contains($file, '/app/') && ! str_contains($file, '/vendor/') && ! str_contains($file, '/tests/')) {
                        $this->sources[$event->sql][] = str_replace(str_replace('\\', '/', base_path()).'/', '', $file).':'.($frame['line'] ?? 0);
                    }
                }
            }
            $this->queries[] = ['sql' => $event->sql, 'binding_hash' => hash('sha256', serialize($event->bindings)), 'ms' => $event->time, 'source' => $this->sources[$event->sql]];
        });
        Gate::after(function (User $actor, string $ability): void {
            if ($this->recording) {
                $this->policies[$ability] = ($this->policies[$ability] ?? 0) + 1;
            }
        });
        Livewire::listen('profile', function (string $phase, string $id, array $times): void {
            if ($this->recording) {
                $this->lifecycle[$phase] = ($this->lifecycle[$phase] ?? 0) + ($times[1] - $times[0]) * 1000;
            }
        });
        Livewire::listen('render', function ($component): void {
            if ($this->controlDiscovery === null || ! $component instanceof ListRecords) {
                return;
            }
            $default = function (Model $record, Table $table): ?string {
                foreach (['view', 'edit'] as $name) {
                    $action = $table->getAction($name);
                    if (! $action) {
                        continue;
                    }
                    $action->record($record);
                    $group = $action->getGroup();
                    while ($group) {
                        $group->record($record);
                        $group = $group->getGroup();
                    }
                    if ($action->isHidden() || $action->getUrl()) {
                        continue;
                    }

                    return $action->getName();
                }

                return null;
            };
            $component->getTable()->recordAction($this->controlDiscovery === 'default' ? $default : null);
        });
        $resolver = app('view.engine.resolver');
        $original = $resolver->resolve('blade');
        $profiler = $this;
        $resolver->register('blade', fn (): Engine => new class($original, $profiler) implements Engine
        {
            public function __construct(private Engine $original, private AdvancedPerformanceFollowupTest $profiler) {}

            public function get(mixed $path, array $data = []): string
            {
                return $this->profiler->profileView($path, fn (): string => $this->original->get($path, $data));
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->original->{$method}(...$arguments);
            }
        });
    }

    public function profileView(string $path, Closure $render): string
    {
        if (! $this->recording) {
            return $render();
        }
        $start = hrtime(true);
        $this->viewStack[] = 0.0;
        try {
            return $render();
        } finally {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $children = array_pop($this->viewStack);
            if ($this->viewStack !== []) {
                $this->viewStack[array_key_last($this->viewStack)] += $elapsed;
            }
            $name = str_replace(str_replace('\\', '/', base_path()).'/', '', str_replace('\\', '/', $path));
            $this->views[$name] ??= ['calls' => 0, 'inclusive_ms' => 0.0, 'self_ms' => 0.0];
            $this->views[$name]['calls']++;
            $this->views[$name]['inclusive_ms'] += $elapsed;
            $this->views[$name]['self_ms'] += $elapsed - $children;
        }
    }

    private function measure(string $label, string $mode, Closure $run): mixed
    {
        $this->queries = $this->sources = $this->views = $this->viewStack = $this->policies = $this->lifecycle = [];
        memory_reset_peak_usage();
        $memory = memory_get_usage(true);
        $start = hrtime(true);
        $this->recording = true;
        try {
            $result = $run();
        } finally {
            $this->recording = false;
        }
        $wall = (hrtime(true) - $start) / 1e6;
        $sql = array_sum(array_column($this->queries, 'ms'));
        $fingerprints = [];
        foreach ($this->queries as $query) {
            $key = $query['sql'].'|'.$query['binding_hash'];
            $fingerprints[$key] ??= $query + ['executions' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0];
            $fingerprints[$key]['executions']++;
            $fingerprints[$key]['total_ms'] += $query['ms'];
            $fingerprints[$key]['max_ms'] = max($fingerprints[$key]['max_ms'], $query['ms']);
        }
        uasort($fingerprints, fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);
        uasort($this->views, fn (array $a, array $b): int => $b['self_ms'] <=> $a['self_ms']);
        $summary = ['label' => $label, 'mode' => $mode, 'queries' => count($this->queries), 'fingerprints' => count($fingerprints),
            'sql_ms' => $sql, 'wall_ms' => $wall, 'outside_sql_ms' => $wall - $sql,
            'peak_bytes' => memory_get_peak_usage(true), 'extra_peak_bytes' => memory_get_peak_usage(true) - $memory,
            'http_requests' => in_array($mode, ['get', 'livewire'], true) ? 1 : 0, 'livewire_requests' => $mode === 'livewire' ? 1 : 0,
            'policy_evaluations' => array_sum($this->policies), 'lifecycle_ms' => $this->lifecycle];
        $this->measurements[$label] = $summary;
        file_put_contents($this->directory.'/'.$label.'.json', json_encode($summary + ['statements' => array_values($fingerprints),
            'views' => $this->views, 'policies' => $this->policies], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fwrite(STDOUT, 'ADVANCED '.json_encode($summary).PHP_EOL);
        if ($result instanceof TestResponse) {
            $this->assertSame(200, $result->getStatusCode(), $label);
        }

        return $result;
    }

    private function prepare(): void
    {
        Livewire::flushState();
        $this->flushHeaders();
        $this->actingAs($this->reader);
        setPermissionsTeamId(null);
        $this->withSession([LocationContext::SESSION_KEY => $this->branch->id, 'locale' => 'en']);
    }

    private function snapshot(TestResponse $response): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        foreach ($matches[1] as $candidate) {
            $decoded = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5);
            if (str_starts_with(json_decode($decoded, true)['memo']['name'] ?? '', 'App\\')) {
                return $decoded;
            }
        }
        $this->fail('Expected an application Livewire snapshot.');
    }

    private function update(string $snapshot, array $updates = [], array $calls = []): TestResponse
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => str_ends_with($route->getName() ?? '', 'livewire.update'));
        $this->prepare();

        return $this->withHeader('X-Livewire', 'true')->postJson('/'.$route->uri(), ['components' => [[
            'snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls ?: [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]]]);
    }

    public function test_variant_component_initialization_http_refresh_and_actions(): void
    {
        $product = Product::factory()->create(['name' => 'Advanced variants', 'brand_id' => Brand::factory(), 'status' => 'active', 'is_active' => true]);
        $color = Color::factory()->create();
        Route::get('/advanced-variants', fn (): string => Blade::render('@livewire($component, $parameters)', [
            'component' => VariantsRelationManager::class, 'parameters' => ['ownerRecord' => $product, 'pageClass' => ViewProduct::class],
        ]))->middleware(['web', 'auth', SetLocationPermissionContext::class]);
        $created = 0;
        foreach ([1, 20, 100] as $count) {
            while ($created < $count) {
                $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'color_id' => $color->id,
                    'size_id' => Size::factory()->create()->id, 'sale_price' => '123.4567', 'cost_price' => '10',
                    'barcode' => 'ADV-'.str_pad((string) $created, 8, '0', STR_PAD_LEFT)]);
                StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
                    'quantity_on_hand' => '20', 'quantity_available' => '20', 'quantity_reserved' => '0']);
                $created++;
            }
            $first = $product->variants()->orderBy('id')->firstOrFail();
            for ($run = 1; $run <= 3; $run++) {
                $this->prepare();
                setPermissionsTeamId($this->branch->id);
                $this->measure('variants-'.$count.'-component-'.$run, 'component', fn () => Livewire::test(VariantsRelationManager::class,
                    ['ownerRecord' => $product->fresh(), 'pageClass' => ViewProduct::class]));
                $this->prepare();
                $this->measure('variants-'.$count.'-parent-get-'.$run, 'get', fn (): TestResponse => $this->get('/admin/products/'.$product->slug));
                $this->prepare();
                $response = $this->measure('variants-'.$count.'-get-'.$run, 'get', fn (): TestResponse => $this->get('/advanced-variants'));
                $this->assertStringContainsString($first->sku, $response->getContent());
                $snapshot = $this->snapshot($response);
                $this->measure('variants-'.$count.'-refresh-'.$run, 'livewire', fn (): TestResponse => $this->update($snapshot));
                if ($run === 1) {
                    $action = $this->measure('variants-'.$count.'-barcode-action', 'livewire', fn (): TestResponse => $this->update($snapshot, [], [[
                        'path' => '', 'method' => 'mountAction', 'params' => ['barcodePreview', [], ['table' => true, 'recordKey' => (string) $first->id]],
                    ]]));
                    $this->assertStringContainsString($first->barcode, $action->getContent());
                }
            }
        }
        foreach (['get', 'refresh'] as $mode) {
            $this->assertLessThanOrEqual($this->measurements['variants-1-'.$mode.'-3']['queries'] + 3,
                $this->measurements['variants-20-'.$mode.'-3']['queries']);
            $this->assertLessThanOrEqual($this->measurements['variants-20-'.$mode.'-3']['queries'] + 3,
                $this->measurements['variants-100-'.$mode.'-3']['queries']);
        }
    }

    public function test_three_run_http_and_render_scaling(): void
    {
        $product = Product::factory()->create(['name' => 'Advanced listing', 'status' => 'active', 'is_active' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        for ($index = 0; $index < 20; $index++) {
            $order = SalesOrder::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id, 'customer_name' => 'Advanced customer '.$index]);
            SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'variant_id' => $variant->id]);
            SalesInvoice::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id, 'customer_name' => 'Advanced customer '.$index]);
        }
        $created = 0;
        foreach ([1, 20, 100] as $count) {
            while ($created < $count) {
                $account = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
                app(TreasuryService::class)->createDraft($this->admin, ['type' => 'cash_deposit', 'account_id' => $account->id,
                    'amount' => '10', 'effective_date' => now()->toDateString(), 'source_type' => 'external_other',
                    'source_reference' => (string) Str::uuid7(), 'reason' => 'Advanced benchmark'], (string) Str::uuid7());
                $created++;
            }
            $paths = ['accounts' => '/admin/treasury-accounts', 'operations' => '/admin/treasury-operations'];
            if ($count === 20) {
                $paths += ['sales-orders' => '/admin/sales-orders', 'sales-invoices' => '/admin/sales-invoices', 'products' => '/admin/products'];
            }
            foreach ($paths as $label => $path) {
                for ($run = 1; $run <= 3; $run++) {
                    $this->prepare();
                    $response = $this->measure($label.'-'.$count.'-get-'.$run, 'get', fn (): TestResponse => $this->get($path));
                    $this->measure($label.'-'.$count.'-all-'.$run, 'livewire', fn (): TestResponse => $this->update($this->snapshot($response), ['tableRecordsPerPage' => 100]));
                }
            }
        }
        $this->assertLessThanOrEqual($this->measurements['operations-1-all-3']['queries'] + 5,
            $this->measurements['operations-20-all-3']['queries']);
        $this->assertLessThanOrEqual($this->measurements['operations-20-all-3']['queries'] + 5,
            $this->measurements['operations-100-all-3']['queries']);
        $this->assertLessThanOrEqual($this->measurements['accounts-20-all-3']['queries'] + 3,
            $this->measurements['accounts-100-all-3']['queries']);
    }

    private function draft(TreasuryAccount $account, ?TreasuryAccount $destination = null): TreasuryOperation
    {
        return app(TreasuryService::class)->createDraft($this->admin, [
            'type' => $destination === null ? 'cash_deposit' : 'transfer', 'account_id' => $account->id,
            'destination_account_id' => $destination?->id, 'amount' => '10', 'effective_date' => now()->toDateString(),
            'source_type' => $destination === null ? 'external_other' : 'internal_transfer',
            'source_reference' => (string) Str::uuid7(), 'reason' => 'Isolated ownership test',
        ], (string) Str::uuid7());
    }

    public function test_operation_ownership_batch_is_persisted_scoped_and_invalidated(): void
    {
        $own = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
        $foreign = TreasuryAccount::factory()->create(['location_id' => $this->foreign->id, 'created_by' => $this->admin->id]);
        $central = TreasuryAccount::factory()->central()->create(['created_by' => $this->admin->id]);
        $ownOperation = $this->draft($own);
        $foreignOperation = $this->draft($foreign);
        $centralOperation = $this->draft($central);
        $transfer = $this->draft($own, $foreign);
        $workspace = app(TreasuryWorkspace::class);
        $forged = clone $foreignOperation;
        $forged->account_id = $own->id;
        $forged->setRelation('account', $own);
        $forged->setConnection('sqlite');
        $this->assertFalse($workspace->canOperate($this->reader, $forged, TreasuryPermissions::VIEW));
        $ids = [$ownOperation->id, $foreignOperation->id, $centralOperation->id, $transfer->id];
        RequestAuthorizationFacts::begin(request());
        try {
            $workspace->primeOperationOwnership($this->reader, $ids);
            $this->assertTrue($workspace->canOperate($this->reader, $ownOperation, TreasuryPermissions::VIEW));
            foreach ([$forged, $centralOperation, $transfer] as $operation) {
                $this->assertFalse($workspace->canOperate($this->reader, $operation, TreasuryPermissions::VIEW));
            }
            $this->reader->givePermissionTo(TreasuryPermissions::VIEW_ALL_LOCATIONS);
            $workspace->primeOperationOwnership($this->reader, $ids);
            $this->assertTrue($workspace->canOperate($this->reader, $foreignOperation, TreasuryPermissions::VIEW));
            $this->assertTrue($workspace->canOperate($this->reader, $centralOperation, TreasuryPermissions::VIEW));
            $this->assertFalse($workspace->canOperate($this->reader, $transfer, TreasuryPermissions::POST));
            $this->assertFalse($workspace->canOperate($this->reader, $centralOperation, TreasuryPermissions::POST));
            $this->reader->revokePermissionTo(TreasuryPermissions::VIEW_ALL_LOCATIONS);
            $this->assertNull(RequestAuthorizationFacts::readFacts('context-switch-probe', function (): array {
                setPermissionsTeamId($this->foreign->id);

                return [1];
            }));
            setPermissionsTeamId($this->branch->id);
            $this->assertNull(RequestAuthorizationFacts::readFacts('context-switch-probe'));
            $tamperedTransfer = clone $transfer;
            $tamperedTransfer->destination_account_id = null;
            $this->assertFalse($workspace->canOperate($this->reader, $tamperedTransfer, TreasuryPermissions::VIEW));
            $ownCopy = clone $ownOperation;
            $ownCopy->account_id = $foreign->id;
            $this->assertTrue($workspace->canOperate($this->reader, $ownCopy, TreasuryPermissions::VIEW));
            $this->reader->revokePermissionTo(TreasuryPermissions::VIEW);
            $this->assertFalse($workspace->canOperate($this->reader, $ownOperation, TreasuryPermissions::VIEW));
            $this->reader->givePermissionTo(TreasuryPermissions::VIEW);
            $workspace->primeOperationOwnership($this->reader, [$ownOperation->id]);
            DB::beginTransaction();
            try {
                DB::table('treasury_operations')->where('id', $ownOperation->id)->update(['account_id' => $foreign->id]);
                $workspace->primeOperationOwnership($this->reader, [$ownOperation->id]);
                $this->assertFalse($workspace->canOperate($this->reader, $ownOperation, TreasuryPermissions::VIEW));
            } finally {
                DB::rollBack();
            }
            $this->assertTrue($workspace->canOperate($this->reader, $ownOperation, TreasuryPermissions::VIEW));
            $workspace->primeOperationOwnership($this->reader, [$ownOperation->id]);
            DB::table('treasury_operations')->where('id', $ownOperation->id)->update(['account_id' => $foreign->id]);
            $this->assertFalse($workspace->canOperate($this->reader, $ownOperation, TreasuryPermissions::VIEW));
            DB::table('treasury_operations')->where('id', $ownOperation->id)->update(['account_id' => $own->id]);
            $this->reader->locations()->attach($this->foreign);
            setPermissionsTeamId($this->foreign->id);
            $this->reader->givePermissionTo(TreasuryPermissions::VIEW);
            $workspace->primeOperationOwnership($this->reader, $ids);
            $this->assertTrue($workspace->canOperate($this->reader, $foreignOperation, TreasuryPermissions::VIEW));
            $this->assertFalse($workspace->canOperate($this->reader, $ownOperation, TreasuryPermissions::VIEW));
            setPermissionsTeamId($this->branch->id);
            $this->assertTrue($workspace->canOperate($this->reader, $ownOperation, TreasuryPermissions::VIEW));
            $this->assertFalse($workspace->canOperate($this->reader, $foreignOperation, TreasuryPermissions::VIEW));
            $workspace->primeOperationOwnership($this->admin, $ids);
            $this->assertTrue($workspace->canOperate($this->admin, $foreignOperation, TreasuryPermissions::VIEW));
            $this->assertTrue($workspace->canOperate($this->admin, $centralOperation, TreasuryPermissions::VIEW));
            $this->assertFalse($workspace->canOperate($this->reader, $centralOperation, TreasuryPermissions::VIEW));
            Module::where('code', 'treasury')->update(['is_active' => false]);
            $this->assertFalse($workspace->canOperate($this->admin, $ownOperation, TreasuryPermissions::VIEW));
        } finally {
            RequestAuthorizationFacts::end(request());
        }
        $this->assertNull(RequestAuthorizationFacts::readBoundary());
    }

    public function test_operation_batch_query_growth_is_bounded_and_row_links_are_preserved(): void
    {
        $account = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
        $operations = collect();
        for ($index = 0; $index < 20; $index++) {
            $operations->push($this->draft($account));
        }
        $counts = [];
        foreach ([1, 20] as $count) {
            RequestAuthorizationFacts::begin(request());
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $workspace = app(TreasuryWorkspace::class);
                $workspace->primeOperationOwnership($this->reader, $operations->take($count)->pluck('id')->all());
                foreach ($operations->take($count) as $operation) {
                    $this->assertTrue($workspace->canOperate($this->reader, $operation, TreasuryPermissions::VIEW));
                }
                $counts[$count] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
                RequestAuthorizationFacts::end(request());
            }
        }
        $this->assertLessThanOrEqual($counts[1] + 5, $counts[20], 'Authoritative ownership reads must not grow per rendered operation.');
        $this->prepare();
        $response = $this->get('/admin/treasury-accounts')->assertOk();
        $this->assertStringContainsString('/admin/treasury-accounts/'.$account->uuid, $response->getContent());
        setPermissionsTeamId($this->branch->id);
        $order = SalesOrder::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
        $invoice = SalesInvoice::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
        $product = Product::factory()->create();
        $navigation = [
            [ListTreasuryAccounts::class, $account],
            [ListSalesOrders::class, $order],
            [ListSalesInvoices::class, $invoice],
            [ListProducts::class, $product],
        ];
        foreach ($navigation as [$page, $record]) {
            $component = Livewire::test($page);
            $this->assertNull($component->instance()->getTable()->getRecordAction($record));
            $this->assertNotNull($component->instance()->getTable()->getRecordUrl($record));
            $component->assertTableActionVisible('view', $record)->assertTableActionVisible('edit', $record);
        }
    }

    public function test_original_component_counts_are_middleware_disabled_diagnostics(): void
    {
        $product = Product::factory()->create(['status' => 'active', 'is_active' => true]);
        $created = 0;
        foreach ([1, 20, 100] as $count) {
            while ($created < $count) {
                ProductVariant::factory()->create(['product_id' => $product->id]);
                $created++;
            }
            $this->prepare();
            setPermissionsTeamId($this->branch->id);
            RequestAuthorizationFacts::begin(request());
            try {
                $component = $this->measure('original-component-'.$count, 'component', fn () => Livewire::test(VariantsRelationManager::class,
                    ['ownerRecord' => $product, 'pageClass' => ViewProduct::class]));
                $component->assertOk();
            } finally {
                RequestAuthorizationFacts::end(request());
            }
        }
    }

    public function test_paired_navigation_discovery_control_on_twenty_product_and_invoice_rows(): void
    {
        for ($index = 0; $index < 20; $index++) {
            Product::factory()->create(['name' => 'Control sneaker '.str_pad((string) $index, 3, '0', STR_PAD_LEFT)]);
            SalesInvoice::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
        }
        try {
            foreach (['products' => '/admin/products', 'invoices' => '/admin/sales-invoices'] as $label => $path) {
                for ($run = 1; $run <= 3; $run++) {
                    foreach ($run === 2 ? ['explicit', 'default'] : ['default', 'explicit'] as $mode) {
                        $this->controlDiscovery = $mode;
                        $this->prepare();
                        $response = $this->measure('control-'.$label.'-'.$mode.'-get-'.$run, 'get', fn (): TestResponse => $this->get($path));
                        $update = $this->measure('control-'.$label.'-'.$mode.'-all-'.$run, 'livewire',
                            fn (): TestResponse => $this->update($this->snapshot($response), ['tableRecordsPerPage' => 25]));
                        if ($label === 'products') {
                            $this->assertStringContainsString('Control sneaker 019', $update->json('components.0.effects.html'));
                        }
                    }
                }
                $this->assertLessThan($this->measurements['control-'.$label.'-default-all-3']['policy_evaluations'],
                    $this->measurements['control-'.$label.'-explicit-all-3']['policy_evaluations']);
            }
        } finally {
            $this->controlDiscovery = null;
        }
    }
}
