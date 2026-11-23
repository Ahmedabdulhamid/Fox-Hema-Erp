<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentPresentation;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Sales\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesReturn;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ModuleActionQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_action_queries_are_bounded_for_one_through_one_hundred_documents(): void
    {
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $branch = Location::create(['name' => 'Query growth branch', 'type' => 'branch', 'is_active' => true]);
        $reader = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $reader->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $reader->givePermissionTo(Permission::where('guard_name', 'web')->where('name', 'not like', '%all_locations%')->pluck('name')->all());
        $order = SalesOrder::factory()->create(['location_id' => $branch->id, 'created_by' => $admin->id]);
        $previous = 0;
        $counts = [];
        foreach ([1, 10, 20, 50, 100] as $size) {
            PurchaseOrder::factory()->count($size - $previous)->create(['location_id' => $branch->id, 'created_by' => $admin->id]);
            $returns = SalesReturn::factory()->count($size - $previous)->create(['location_id' => $branch->id,
                'sales_order_id' => $order->id, 'created_by' => $admin->id]);
            DB::table('sales_returns')->whereIn('id', $returns->modelKeys())->update(['submission_payload_hash' => hash('sha256', 'query fixture')]);
            foreach (['admin' => $admin, 'restricted' => $reader] as $actorType => $actor) {
                $this->flushSession();
                $this->actingAs($actor)->withSession([LocationContext::SESSION_KEY => $branch->id]);
                foreach ([PurchaseOrderResource::class, SalesReturnResource::class] as $resource) {
                    $page = $resource::getPages()['index']->getPage();
                    $this->withSession(['tables.'.md5($page).'_per_page' => 100]);
                    app('livewire')->flushState();
                    DB::flushQueryLog();
                    DB::enableQueryLog();
                    try {
                        $response = $this->get($resource::getUrl('index'));
                        $response->assertStatus(200, $response->headers->get('Location', ''));
                        $response->assertSee('wire:key', false);
                        $log = DB::getQueryLog();
                    } finally {
                        DB::disableQueryLog();
                        DB::flushQueryLog();
                    }
                    $key = class_basename($resource).'-'.$actorType;
                    $counts[$key][] = count($log);
                    fwrite(STDOUT, 'MODULE_SCALE '.json_encode(['page' => $key, 'records' => $size, 'queries' => count($log)], JSON_THROW_ON_ERROR).PHP_EOL);
                    $this->assertSame([], array_values(array_filter($log, fn (array $query): bool => str_starts_with($query['query'], 'select exists') && (str_contains($query['query'], '`sales_credit_notes`')
                            || str_contains($query['query'], '`vendor_bills`') || str_contains($query['query'], '`purchase_receipts`')))),
                        'Row action history must be included in the table SELECT rather than queried per document.');
                }
            }
            $previous = $size;
        }
        foreach ($counts as $key => $measurements) {
            $this->assertLessThanOrEqual(4, max($measurements) - min($measurements), $key.' query growth must remain bounded.');
        }
    }

    public function test_related_receipt_links_batch_policy_relationships_and_preserve_authorization(): void
    {
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->provisionTestSuperAdmin();
        $this->actingAs($admin);
        $order = PurchaseOrder::factory()->create();
        PurchaseReceipt::factory()->count(20)->create(['purchase_order_id' => $order->id, 'location_id' => $order->location_id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $links = app(DocumentPresentation::class)->links($order, $admin);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(12, $links);
        $this->assertCount(1, array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from `purchase_orders`')));
        $reader = User::factory()->create(['is_active' => true]);
        $this->assertSame([], app(DocumentPresentation::class)->links($order, $reader));
    }

    public function test_source_uuid_normalization_batches_rows_and_preserves_uppercase_and_unknown_values(): void
    {
        $order = SalesOrder::factory()->create();
        $items = SalesOrderItem::factory()->count(3)->create(['sales_order_id' => $order->id]);
        $lines = [];
        for ($index = 0; $index < 100; $index++) {
            $lines[] = ['sales_order_item_id' => strtoupper($items[$index % 3]->uuid), 'quantity' => '1'];
        }
        $lines[] = ['sales_order_item_id' => (string) Str::uuid7(), 'quantity' => '1'];
        $lines[] = 'invalid line';
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $normalized = DocumentSelectors::normalizeInput(['sales_order_id' => strtoupper($order->uuid), 'items' => $lines]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertLessThanOrEqual(2, count($queries), 'Public identifier normalization must batch source rows.');
        $this->assertSame($order->id, $normalized['sales_order_id']);
        for ($index = 0; $index < 100; $index++) {
            $this->assertSame($items[$index % 3]->id, $normalized['items'][$index]['sales_order_item_id']);
        }
        $this->assertSame(0, $normalized['items'][100]['sales_order_item_id']);
        $this->assertSame('invalid line', $normalized['items'][101]);
    }

    public function test_purchase_order_history_is_rechecked_after_a_same_request_write(): void
    {
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        $admin = $this->provisionTestSuperAdmin();
        $this->actingAs($admin);
        $order = PurchaseOrder::factory()->create();
        RequestAuthorizationFacts::duringRead(function () use ($admin, $order): void {
            $this->assertTrue($admin->can('cancel', $order));
            $this->assertTrue($admin->can('cancel', $order));
            PurchaseReceipt::factory()->create(['purchase_order_id' => $order->id, 'location_id' => $order->location_id]);
            $this->assertFalse($admin->can('cancel', $order));
        });
    }
}
