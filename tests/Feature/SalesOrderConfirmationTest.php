<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesStockReservation;
use App\Modules\Sales\Services\SalesLinePricing;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Taxes\Models\Tax;
use Brick\Math\BigDecimal;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SalesOrderConfirmationTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private SalesOrderService $drafts;

    private SalesOrderConfirmationService $workflow;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.isolated-purchasing-tests');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'sales')->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        $this->branch = Location::create(['name' => 'Reservation branch', 'type' => 'branch', 'is_active' => true]);
        $product = Product::factory()->create(['status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sale_price' => '10']);
        $this->balance($this->variant, '10');
        $this->drafts = app(SalesOrderService::class);
        $this->workflow = app(SalesOrderConfirmationService::class);
    }

    private function balance(ProductVariant $variant, string $onHand, string $reserved = '0'): StockBalance
    {
        return StockBalance::updateOrCreate(['variant_id' => $variant->id, 'location_id' => $this->branch->id], [
            'quantity_on_hand' => $onHand, 'quantity_reserved' => $reserved,
            'quantity_available' => (string) BigDecimal::of($onHand)->minus($reserved)->toScale(4),
        ]);
    }

    private function draft(string $quantity = '5', ?array $lines = null): SalesOrder
    {
        return $this->drafts->create($this->actor, ['location_id' => $this->branch->id, 'ordered_at' => '2026-10-17 12:00:00',
            'items' => $lines ?? [$this->line($this->variant, $quantity)]], (string) Str::uuid7());
    }

    private function line(ProductVariant $variant, string $quantity): array
    {
        return ['product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => $quantity,
            'unit_price' => '10.123456', 'discount_amount' => '0', 'tax_amount' => '0'];
    }

    public function test_draft_catalog_changes_require_review_and_confirmed_evidence_stays_frozen(): void
    {
        $tax = Tax::factory()->create(['rate' => '14']);
        $discount = Discount::factory()->create(['value' => '10']);
        $this->variant->product->taxes()->attach($tax);
        $this->variant->product->discounts()->attach($discount);
        $line = Arr::except($this->line($this->variant, '2'), ['discount_amount', 'tax_amount']);
        $order = $this->draft('2', [$line]);
        $this->assertSame('2.0247', $order->discount_amount);
        $tax->update(['is_active' => false]);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->assertSame('draft', $order->fresh()->status);
        $order = $this->drafts->update($this->actor, $order, ['location_id' => $this->branch->id,
            'items' => array_map(fn (array $item): array => array_replace($item, ['tax_ids' => []]), $this->drafts->formItems($order))], $this->drafts->revision($order));
        $this->assertSame('0.0000', $order->tax_amount);
        $order = $this->workflow->confirm($this->actor, $order);
        $before = $order->items->first()->commercial_snapshot;
        $discount->update(['is_active' => false, 'name' => 'Renamed promotion']);
        $tax->update(['is_active' => true]);
        $this->workflow->confirm($this->actor, $order);
        $this->assertSame($before, $order->fresh()->items->first()->commercial_snapshot);
    }

    public function test_legacy_confirmation_fingerprint_ignores_only_null_added_evidence(): void
    {
        $order = $this->workflow->confirm($this->actor, $this->draft());
        DB::table('sales_order_items')->where('sales_order_id', $order->id)->update(['commercial_snapshot' => null]);
        $order->refresh();
        $header = Arr::except($order->getAttributes(), ['status', 'fulfillment_status', 'confirmed_at', 'confirmed_by',
            'cancelled_at', 'cancelled_by', 'confirmation_payload_hash', 'cancellation_payload_hash', 'created_at', 'updated_at']);
        $items = $order->items()->orderBy('id')->get()->map(fn ($item): array => Arr::except($item->getAttributes(), ['commercial_snapshot']))->all();
        $legacyHash = hash('sha256', json_encode([$header, $items], JSON_THROW_ON_ERROR));
        DB::table('sales_orders')->where('id', $order->id)->update(['confirmation_payload_hash' => $legacyHash]);
        $this->assertSame($legacyHash, $this->workflow->fingerprint($order->refresh()));
        $this->assertSame('confirmed', $this->workflow->confirm($this->actor, $order)->status);
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught, $caught->getMessage());

            return;
        }
        $this->fail('Expected rejection.');
    }

    private function scoped(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($user, $this->branch->id);
        $role = Role::create(['name' => 'sales-confirm-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_confirmation_aggregates_lines_preserves_existing_holds_and_is_idempotent(): void
    {
        $balance = $this->balance($this->variant, '10', '2');
        $order = $this->draft(lines: [$this->line($this->variant, '2'), $this->line($this->variant, '3')]);
        $revision = $this->drafts->revision($order);
        $confirmed = $this->workflow->confirm($this->actor, $order, $revision);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame($this->actor->id, $confirmed->confirmed_by);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertNotNull($confirmed->confirmation_payload_hash);
        $this->assertSame('10.0000', $balance->fresh()->quantity_on_hand);
        $this->assertSame('7.0000', $balance->fresh()->quantity_reserved);
        $this->assertSame('3.0000', $balance->fresh()->quantity_available);
        $this->assertCount(1, $confirmed->reservations);
        $this->assertSame('5.0000', $confirmed->reservations[0]->activeQuantity());
        $attributes = $confirmed->getAttributes();
        $reservations = $confirmed->reservations->map->getAttributes()->all();
        $repeat = $this->workflow->confirm($this->actor, $order, $revision);
        $this->assertSame($attributes, $repeat->getAttributes());
        $this->assertSame($reservations, $repeat->reservations->map->getAttributes()->all());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, DB::table('inventory_cost_entries')->count());
    }

    public function test_confirmation_rechecks_stock_and_missing_balances_without_partial_writes(): void
    {
        $order = $this->draft();
        $this->balance($this->variant, '4');
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(0, SalesStockReservation::count());
        StockBalance::where('variant_id', $this->variant->id)->delete();
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->assertSame(0, StockBalance::count());
    }

    public function test_decimal_reservations_and_release_remain_exact(): void
    {
        $balance = $this->balance($this->variant, '0.3000');
        $order = $this->draft(lines: [$this->line($this->variant, '0.1'), $this->line($this->variant, '0.2')]);
        $confirmed = $this->workflow->confirm($this->actor, $order);
        $this->assertSame('0.3000', $confirmed->reservations[0]->quantity_reserved);
        $this->assertSame('0.0000', $balance->fresh()->quantity_available);
        $cancelled = $this->workflow->cancel($this->actor, $confirmed);
        $this->assertSame('0.3000', $cancelled->reservations[0]->quantity_released);
        $this->assertSame('0.0000', $cancelled->reservations[0]->activeQuantity());
        $this->assertSame('0.3000', $balance->fresh()->quantity_available);
    }

    public function test_empty_invalid_inactive_and_unallocated_lines_cannot_be_confirmed(): void
    {
        $order = $this->draft();
        $this->variant->update(['is_active' => false]);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->variant->update(['is_active' => true]);
        $this->variant->product->update(['status' => 'archived']);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->variant->product->update(['status' => 'active']);
        $unallocated = ProductVariant::factory()->make(['product_id' => $this->variant->product_id]);
        $unallocated->forceFill(['unallocated_product_id' => $this->variant->product_id])->save();
        DB::table('sales_order_items')->where('sales_order_id', $order->id)->update(['variant_id' => $unallocated->id]);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        DB::table('sales_order_items')->where('sales_order_id', $order->id)->update(['variant_id' => $this->variant->id, 'quantity' => '0']);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $order->items()->get()->each->delete();
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(0, SalesStockReservation::count());
    }

    public function test_amount_corruption_and_stale_confirmation_are_rejected(): void
    {
        $order = $this->draft();
        $revision = $this->drafts->revision($order);
        $order->update(['notes' => 'Changed']);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order, $revision));
        DB::table('sales_orders')->where('id', $order->id)->update(['total_amount' => '999']);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->assertSame(0, SalesStockReservation::count());
        $this->assertSame('0.0000', StockBalance::first()->quantity_reserved);
    }

    public function test_failure_after_first_reservation_rolls_back_all_lines_balances_and_status(): void
    {
        $second = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        $this->balance($second, '10');
        $order = $this->draft(lines: [$this->line($this->variant, '3'), $this->line($second, '4')]);
        $before = StockBalance::orderBy('id')->get()->map->getAttributes()->all();
        StockBalance::saving(function (StockBalance $balance) use ($second): void {
            if ($balance->variant_id === $second->id && BigDecimal::of($balance->quantity_reserved)->isPositive()) {
                throw new RuntimeException('Injected second balance failure.');
            }
        });
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order), RuntimeException::class);
        $this->assertSame($before, StockBalance::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertNull($order->fresh()->confirmed_at);
        $this->assertSame(0, SalesStockReservation::count());
    }

    public function test_confirmed_cancellation_releases_only_owned_stock_and_preserves_audit(): void
    {
        $balance = $this->balance($this->variant, '10', '2');
        $order = $this->workflow->confirm($this->actor, $this->draft());
        $confirmedAt = $order->confirmed_at->format('Y-m-d H:i:s');
        $this->variant->update(['is_active' => false]);
        $cancelled = $this->workflow->cancel($this->actor, $order);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($confirmedAt, $cancelled->confirmed_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->actor->id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('5.0000', $cancelled->reservations[0]->quantity_reserved);
        $this->assertSame('5.0000', $cancelled->reservations[0]->quantity_released);
        $this->assertSame('0.0000', $cancelled->reservations[0]->quantity_consumed);
        $this->assertSame('2.0000', $balance->fresh()->quantity_reserved);
        $this->assertSame('8.0000', $balance->fresh()->quantity_available);
        $this->assertSame('10.0000', $balance->fresh()->quantity_on_hand);
        $before = $cancelled->getAttributes();
        $this->assertSame($before, $this->workflow->cancel($this->actor, $order)->getAttributes());
        $this->reject(fn () => $this->workflow->confirm($this->actor, $cancelled));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_draft_cancellation_has_no_stock_effect_and_is_idempotent(): void
    {
        $order = $this->draft();
        $before = StockBalance::first()->getAttributes();
        $cancelled = $this->workflow->cancel($this->actor, $order);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($before, StockBalance::first()->getAttributes());
        $this->assertSame(0, SalesStockReservation::count());
        $this->assertSame($cancelled->getAttributes(), $this->workflow->cancel($this->actor, $order)->getAttributes());
    }

    public function test_model_writes_and_quiet_writes_cannot_mutate_confirmed_orders_or_reservations(): void
    {
        $order = $this->draft();
        $stale = $order->fresh();
        $this->reject(fn () => $order->update(['status' => 'confirmed']));
        $order = $this->workflow->confirm($this->actor, $order->fresh());
        foreach (['notes' => 'forged', 'location_id' => 999, 'total_amount' => '1', 'status' => 'draft'] as $field => $value) {
            $this->reject(fn () => $order->fresh()->update([$field => $value]));
        }
        $stale->notes = 'stale bypass';
        $this->reject(fn () => $stale->saveQuietly());
        $item = $order->items()->firstOrFail();
        foreach (['quantity' => '6', 'unit_price' => '1', 'discount_amount' => '1', 'tax_amount' => '1', 'variant_id' => 999] as $field => $value) {
            $this->reject(fn () => $item->fresh()->update([$field => $value]));
        }
        $this->reject(fn () => $item->delete());
        $this->reject(fn () => $item->fresh()->forceFill(['quantity' => '7'])->saveQuietly());
        $this->reject(fn () => $order->items()->create(Arr::except($item->toArray(), ['id', 'uuid', 'created_at', 'updated_at'])));
        $reservation = $order->reservations[0];
        $this->reject(fn () => $reservation->forceFill(['quantity_released' => '5'])->saveQuietly());
        $this->reject(fn () => $reservation->delete());
        $this->reject(fn () => $order->delete());
    }

    public function test_conflicting_replays_and_fulfilled_or_consumed_orders_are_rejected(): void
    {
        $order = $this->workflow->confirm($this->actor, $this->draft());
        DB::table('sales_orders')->where('id', $order->id)->update(['notes' => 'tampered']);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->reject(fn () => $this->workflow->cancel($this->actor, $order));
        DB::table('sales_orders')->where('id', $order->id)->update(['notes' => null]);
        DB::table('sales_stock_reservations')->where('sales_order_id', $order->id)->update(['quantity_consumed' => '1']);
        $this->reject(fn () => $this->workflow->cancel($this->actor, $order));
        $this->assertFalse(Gate::allows('cancel', $order->fresh()));
        DB::table('sales_stock_reservations')->where('sales_order_id', $order->id)->update(['quantity_consumed' => '0']);
        foreach (['partially_fulfilled', 'fulfilled', 'closed'] as $status) {
            DB::table('sales_orders')->where('id', $order->id)->update(['status' => $status, 'fulfillment_status' => 'partial']);
            $this->reject(fn () => $this->workflow->cancel($this->actor, $order));
        }
        $this->assertSame('5.0000', StockBalance::first()->quantity_reserved);
    }

    public function test_permissions_and_cross_branch_access_are_rechecked_by_backend_services(): void
    {
        $order = $this->draft();
        $viewer = $this->scoped([SalesPermissions::VIEW]);
        $this->reject(fn () => $this->workflow->confirm($viewer, $order), HttpException::class);
        $this->reject(fn () => $this->workflow->cancel($viewer, $order), HttpException::class);
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->assertActionHidden('confirm')->assertActionHidden('cancel');
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $user = $this->scoped(SalesPermissions::all());
        DB::table('sales_orders')->where('id', $order->id)->update(['location_id' => $other->id]);
        $this->reject(fn () => $this->workflow->confirm($user, $order), HttpException::class);
        $this->reject(fn () => $this->workflow->cancel($user, $order), HttpException::class);
        $this->assertSame(0, SalesStockReservation::count());
    }

    public function test_inconsistent_balance_and_owner_counters_fail_without_adjusting_stock(): void
    {
        $order = $this->draft();
        foreach ([['quantity_available' => '9'], ['quantity_reserved' => '11'], ['quantity_on_hand' => '-1']] as $bad) {
            $this->balance($this->variant, '10');
            DB::table('stock_balances')->where('variant_id', $this->variant->id)->update($bad);
            $before = StockBalance::first()->getAttributes();
            $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
            $this->assertSame($before, StockBalance::first()->getAttributes());
            $this->assertSame(0, SalesStockReservation::count());
        }
        $this->balance($this->variant, '10');
        $order = $this->workflow->confirm($this->actor, $order);
        $this->balance($this->variant, '10', '4');
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->reject(fn () => $this->workflow->cancel($this->actor, $order));
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_schema_checks_uniqueness_and_composite_branch_ownership_are_enforced(): void
    {
        $order = $this->workflow->confirm($this->actor, $this->draft());
        $reservation = $order->reservations[0];
        $this->reject(fn () => DB::table('sales_stock_reservations')->where('id', $reservation->id)->update(['quantity_released' => '6']), QueryException::class);
        $duplicate = Arr::except($reservation->getAttributes(), ['id']);
        $duplicate['uuid'] = (string) Str::uuid7();
        $this->reject(fn () => DB::table('sales_stock_reservations')->insert($duplicate), QueryException::class);
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $duplicate['location_id'] = $other->id;
        $this->reject(fn () => DB::table('sales_stock_reservations')->insert($duplicate), QueryException::class);
        $this->assertSame(1, SalesStockReservation::count());
    }

    public function test_filament_actions_confirm_and_cancel_with_reservation_history(): void
    {
        app()->setLocale('ar');
        $order = $this->draft();
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->assertActionVisible('confirm')
            ->callAction('confirm')->assertHasNoActionErrors()->assertNotified(__('Sales order confirmed and stock reserved.'));
        $this->assertSame('confirmed', $order->fresh()->status);
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->assertActionHidden('confirm')->assertActionVisible('cancel')
            ->assertSee('5.0000')->callAction('cancel')->assertHasNoActionErrors()->assertNotified(__('Sales order cancelled and reservations released.'));
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_permission_registration_preserves_existing_grants(): void
    {
        $user = $this->scoped([SalesPermissions::VIEW, SalesPermissions::CONFIRM]);
        app(ModuleRegistry::class)->register();
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertTrue($user->can(SalesPermissions::CONFIRM));
        $this->assertFalse($user->can(SalesPermissions::CANCEL));
        $this->assertTrue(Module::where('code', 'sales')->firstOrFail()->permissions()->where('name', SalesPermissions::CANCEL)->exists());
    }

    public function test_cancellation_failure_rolls_back_releases_balances_and_status(): void
    {
        $second = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        $this->balance($second, '10');
        $order = $this->workflow->confirm($this->actor,
            $this->draft(lines: [$this->line($this->variant, '3'), $this->line($second, '4')]));
        $balances = StockBalance::orderBy('id')->get()->map->getAttributes()->all();
        $owners = SalesStockReservation::orderBy('id')->get()->map->getAttributes()->all();
        StockBalance::saving(function (StockBalance $balance) use ($second): void {
            if ($balance->variant_id === $second->id && BigDecimal::of($balance->quantity_reserved)->isZero()) {
                throw new RuntimeException('Injected release failure.');
            }
        });
        $this->reject(fn () => $this->workflow->cancel($this->actor, $order), RuntimeException::class);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
        $this->assertSame($balances, StockBalance::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($owners, SalesStockReservation::orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_contradictory_lifecycle_audit_metadata_is_never_treated_as_a_successful_retry(): void
    {
        $order = $this->draft();
        DB::table('sales_orders')->where('id', $order->id)->update(['cancelled_at' => now()]);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->reject(fn () => $this->workflow->cancel($this->actor, $order));
        DB::table('sales_orders')->where('id', $order->id)->update(['cancelled_at' => null]);
        $order = $this->workflow->confirm($this->actor, $order);
        DB::table('sales_orders')->where('id', $order->id)->update(['cancellation_payload_hash' => hash('sha256', 'conflict')]);
        $this->reject(fn () => $this->workflow->confirm($this->actor, $order));
        $this->reject(fn () => $this->workflow->cancel($this->actor, $order));
        $this->assertSame('5.0000', StockBalance::first()->quantity_reserved);
    }

    public function test_filament_stock_failure_shows_arabic_notification_and_keeps_the_draft(): void
    {
        app()->setLocale('ar');
        $order = $this->draft();
        $this->balance($this->variant, '1');
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->callAction('confirm')
            ->assertNotified(__('Sales order operation failed'));
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(0, SalesStockReservation::count());
        $this->assertSame('0.0000', StockBalance::first()->quantity_reserved);
    }

    public function test_concurrent_confirm_and_cancel_produce_one_consistent_transition(): void
    {
        $order = $this->draft('7');
        $results = $this->concurrent([['confirm', $order], ['cancel', $order]]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => isset($result['rejected'])));
        $order = $order->fresh();
        $this->assertContains($order->status, ['confirmed', 'cancelled']);
        $this->assertSame($order->status === 'confirmed' ? '7.0000' : '0.0000', StockBalance::first()->quantity_reserved);
        $this->assertSame($order->status === 'confirmed' ? 1 : 0, SalesStockReservation::count());
        $this->workflow->cancel($this->actor, $order);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('0.0000', StockBalance::first()->quantity_reserved);
        $this->assertSame('10.0000', StockBalance::first()->quantity_available);
    }

    private function concurrent(array $operations): array
    {
        $configuration = config('database.connections.mysql');
        $database = DB::connection()->getDatabaseName();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringStartsWith('codex_public_ids_test_purchasing_', $database);
        $processes = [];
        $start = microtime(true) + 2;
        try {
            foreach ($operations as [$operation, $order]) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/SalesOrderWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'SALES_ACTOR' => (string) $this->actor->id, 'SALES_START' => (string) $start,
                    'SALES_OPERATION' => $operation, 'SALES_ORDER' => (string) $order->id,
                    'SALES_REVISION' => $this->drafts->revision($order->fresh()), 'SALES_ALLOW_REJECTION' => '1',
                ]);
                $process->setTimeout(40)->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_concurrent_different_orders_cannot_reserve_the_same_available_stock(): void
    {
        $first = $this->draft('7');
        $second = $this->draft('7');
        $results = $this->concurrent([['confirm', $first], ['confirm', $second]]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => isset($result['rejected'])));
        $this->assertSame(1, SalesOrder::where('status', 'confirmed')->count());
        $this->assertSame(1, SalesOrder::where('status', 'draft')->count());
        $this->assertSame(1, SalesStockReservation::count());
        $this->assertSame('7.0000', StockBalance::first()->quantity_reserved);
        $this->assertSame('3.0000', StockBalance::first()->quantity_available);
        $this->assertSame('10.0000', StockBalance::first()->quantity_on_hand);
    }

    public function test_mysql_json_round_trip_confirms_saved_taxes_and_discounts_from_both_actions(): void
    {
        $tax = Tax::factory()->create(['rate' => '14']);
        $discount = Discount::factory()->create(['value' => '10']);
        $this->variant->product->taxes()->attach($tax);
        $this->variant->product->discounts()->attach($discount);
        $line = Arr::except($this->line($this->variant, '2'), ['discount_amount', 'tax_amount']);
        foreach ([false, true] as $table) {
            $order = $this->draft('2', [$line]);
            $saved = $order->items()->sole()->commercial_snapshot;
            $calculated = app(SalesLinePricing::class)->line($this->variant, $line)['commercial_snapshot'];
            if (DB::getDriverName() === 'mysql' && ! str_contains(DB::selectOne('SELECT VERSION() AS version')->version, 'MariaDB')) {
                $this->assertNotSame(array_keys($saved), array_keys($calculated));
            }
            $this->assertEquals($calculated, $saved);
            app()->setLocale('ar');
            session(['locale' => 'ar']);
            Livewire::test($table ? ListSalesOrders::class : ViewSalesOrder::class, $table ? [] : ['record' => $order->uuid])
                ->callAction($table ? TestAction::make('confirm')->table($order) : 'confirm')
                ->assertNotified(__('Sales order confirmed and stock reserved.'))->assertRedirect();
            $this->assertSame('confirmed', $order->refresh()->status);
            $this->assertSame('2.0000', $order->reservations()->sole()->quantity_reserved);
            $this->assertSame('10.0000', StockBalance::first()->quantity_on_hand);
            $this->assertSame($saved, $order->items()->sole()->commercial_snapshot);
            $this->workflow->cancel($this->actor, $order);
            $this->assertSame('0.0000', StockBalance::first()->quantity_reserved);
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidPricingEvidence(): array
    {
        return ['changed value' => ['value'], 'changed type' => ['type'], 'missing evidence' => ['missing'],
            'missing nested field' => ['field'], 'reordered list' => ['list']];
    }

    #[DataProvider('invalidPricingEvidence')]
    public function test_changed_or_missing_snapshot_evidence_shows_arabic_feedback_without_reserving_stock(string $change): void
    {
        $taxes = Tax::factory()->count(2)->create(['rate' => '0']);
        $discount = Discount::factory()->create(['value' => '0']);
        $this->variant->product->taxes()->attach($taxes->modelKeys());
        $this->variant->product->discounts()->attach($discount);
        $order = $this->draft();
        $item = $order->items()->sole();
        $snapshot = $item->commercial_snapshot;
        switch ($change) {
            case 'value':
                $snapshot['quantity'] = '6.0000';
                break;
            case 'type':
                $snapshot['version'] = '1';
                break;
            case 'missing':
                $snapshot = null;
                break;
            case 'field':
                unset($snapshot['discount']['code']);
                break;
            case 'list':
                $snapshot['taxes'] = array_reverse($snapshot['taxes']);
                break;
        }
        $item->update(['commercial_snapshot' => $snapshot]);
        app()->setLocale('ar');
        session(['locale' => 'ar']);
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->callAction('confirm')
            ->assertNotified(Notification::make()->title(__('Sales order operation failed'))
                ->body(__('Configured pricing changed or evidence is missing. Save the draft before confirmation.'))->danger())
            ->assertNoRedirect();
        $this->assertSame('draft', $order->refresh()->status);
        $this->assertSame(0, SalesStockReservation::count());
        $this->assertSame('0.0000', StockBalance::first()->quantity_reserved);
        $this->assertSame('10.0000', StockBalance::first()->quantity_on_hand);
    }

    public function test_concurrent_same_order_confirmation_and_cancellation_are_idempotent(): void
    {
        $order = $this->draft('7');
        $results = $this->concurrent([['confirm', $order], ['confirm', $order], ['confirm', $order]]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame($results[0], $results[2]);
        $this->assertSame(1, SalesStockReservation::count());
        $this->assertSame('7.0000', StockBalance::first()->quantity_reserved);
        $order = $order->fresh();
        $results = $this->concurrent([['cancel', $order], ['cancel', $order]]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame('0.0000', StockBalance::first()->quantity_reserved);
        $this->assertSame('10.0000', StockBalance::first()->quantity_available);
        $this->assertSame('7.0000', SalesStockReservation::first()->quantity_released);
    }
}
