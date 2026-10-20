<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\DocumentNumber;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\DocumentPresentation;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ViewVendorBill;
use App\Modules\Purchasing\Services\VendorBillService;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\CreateSalesInvoice;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnService;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\DocumentTestSetup;
use Tests\TestCase;

class DocumentPresentationTest extends TestCase
{
    use DocumentTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    private function scopedDocumentUser(Location $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($branch);
        app(LocationContext::class)->setFor($user, $branch->id);
        $role = Role::create(['name' => 'document-'.Str::uuid7(), 'guard_name' => 'web', 'location_id' => $branch->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_invoice_selector_searches_number_customer_and_branch_with_uuid_values_and_context(): void
    {
        $delivery = $this->documentDelivery();
        $order = $delivery->salesOrder;
        $selectors = app(DocumentSelectors::class);
        foreach ([$order->order_number, 'Document Customer', $this->documentBranch->code] as $search) {
            $results = $selectors->orders($this->documentActor, 'invoice', $search);
            $this->assertArrayHasKey($order->uuid, $results);
            $this->assertStringContainsString('Document Customer', $results[$order->uuid]);
            $this->assertStringContainsString($this->documentBranch->name, $results[$order->uuid]);
            $this->assertStringContainsString('100.0000 EGP', $results[$order->uuid]);
            $this->assertFalse(DocumentPresentation::containsUuid($results[$order->uuid]));
        }
        $line = $delivery->items()->first();
        $lines = $selectors->lines($this->documentActor, 'return', $order->uuid, $delivery->delivery_number);
        $this->assertArrayHasKey($line->uuid, $lines);
        $this->assertStringContainsString($delivery->delivery_number, $lines[$line->uuid]);
        $this->assertStringContainsString('10.0000', $lines[$line->uuid]);

        $page = Livewire::test(CreateSalesInvoice::class)->assertSuccessful();
        $field = collect($page->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field) => $field instanceof Select && $field->getName() === 'sales_order_id');
        $this->assertArrayHasKey($order->uuid, $field->getSearchResults($order->order_number));
    }

    public function test_global_invoice_form_initial_options_and_livewire_search_include_eligible_orders_across_branches(): void
    {
        $first = $this->documentDelivery()->salesOrder;
        $this->documentBranch = Location::create(['name' => 'Second invoice branch', 'type' => 'branch', 'is_active' => true]);
        $second = $this->documentDelivery()->salesOrder;
        $this->assertTrue($this->documentActor->isGlobalSuperAdmin());
        $this->assertNull(getPermissionsTeamId());

        $page = Livewire::test(CreateSalesInvoice::class)->assertSuccessful();
        $field = collect($page->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field) => $field instanceof Select && $field->getName() === 'sales_order_id');
        $this->assertEqualsCanonicalizing([$first->uuid, $second->uuid], array_keys($field->getOptions()));
        $page->call('callSchemaComponentMethod', $field->getKey(), 'getOptionsForJs')->assertReturned($field->getOptionsForJs());
        foreach ([$second->order_number, 'Document Customer'] as $search) {
            $this->assertArrayHasKey($second->uuid, $field->getSearchResults($search));
            $page->call('callSchemaComponentMethod', $field->getKey(), 'getSearchResultsForJs', ['search' => $search])
                ->assertReturned($field->getSearchResultsForJs($search));
        }
        $page->fillForm(['sales_order_id' => $second->uuid])->assertFormSet(['sales_order_id' => $second->uuid]);
        $page->call('callSchemaComponentMethod', $field->getKey(), 'getSearchResultsForJs', ['search' => 'no matching customer'])
            ->assertReturned([]);
    }

    public function test_branch_invoice_form_initial_options_and_livewire_search_exclude_foreign_orders(): void
    {
        $branch = $this->documentBranch;
        $own = $this->documentDelivery()->salesOrder;
        $this->documentBranch = Location::create(['name' => 'Foreign invoice branch', 'type' => 'branch', 'is_active' => true]);
        $foreign = $this->documentDelivery()->salesOrder;
        $this->scopedDocumentUser($branch, SalesPermissions::all());

        $page = Livewire::test(CreateSalesInvoice::class)->assertSuccessful();
        $field = collect($page->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field) => $field instanceof Select && $field->getName() === 'sales_order_id');
        $this->assertSame([$own->uuid], array_keys($field->getOptions()));
        $page->call('callSchemaComponentMethod', $field->getKey(), 'getOptionsForJs')->assertReturned($field->getOptionsForJs());
        $page->call('callSchemaComponentMethod', $field->getKey(), 'getSearchResultsForJs', ['search' => $foreign->order_number])
            ->assertReturned([]);
        $this->assertNull(app(DocumentSelectors::class)->selectedLabel(auth()->user(), 'invoice', $foreign->uuid));
        $this->assertSame([$own->uuid], array_keys($field->getSearchResults('Document Customer')));
    }

    public function test_invoice_form_explains_empty_options_when_posted_deliveries_are_fully_invoiced(): void
    {
        $service = app(SalesInvoiceService::class);
        for ($i = 0; $i < 2; $i++) {
            $delivery = $this->documentDelivery();
            $invoice = $service->create($this->documentActor, ['sales_order_id' => $delivery->salesOrder->uuid,
                'invoice_date' => today()->toDateString(),
                'items' => [['sales_delivery_item_id' => $delivery->items()->first()->uuid, 'quantity' => '10']]], (string) Str::uuid7());
            $service->post($this->documentActor, $invoice, $service->revision($invoice));
        }
        SalesOrder::factory()->create(['location_id' => $this->documentBranch->id]);
        $this->documentPurchase(quantity: '1');
        $undelivered = app(SalesOrderService::class)->create($this->documentActor, ['location_id' => $this->documentBranch->id,
            'customer_name' => 'Undelivered customer', 'items' => [['product_id' => $this->documentVariant->product_id,
                'variant_id' => $this->documentVariant->id, 'description' => 'Not yet delivered',
                'quantity' => '1', 'unit_price' => '10', 'discount_amount' => '0', 'tax_amount' => '0']]], (string) Str::uuid7());
        app(SalesOrderConfirmationService::class)->confirm($this->documentActor, $undelivered);
        $this->assertSame(4, SalesOrder::count());
        $this->assertSame(2, $service->eligibleOrders($this->documentActor)->count());

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $page = Livewire::test(CreateSalesInvoice::class)->assertSuccessful();
            $field = collect($page->instance()->form->getFlatFields(withHidden: true))
                ->first(fn ($field) => $field instanceof Select && $field->getName() === 'sales_order_id');
            $this->assertSame([], $field->getOptions());
            $page->call('callSchemaComponentMethod', $field->getKey(), 'getOptionsForJs')->assertReturned([]);
            $this->assertTrue($field->hasInitialNoOptionsMessage());
            $this->assertSame(__('invoices.no_eligible_orders'), $field->getNoOptionsMessage());
            $this->assertSame(__('invoices.no_matching_orders'), $field->getNoSearchResultsMessage());
            $page->assertSee(__('invoices.order_eligibility_help'));
        }
    }

    public function test_uuid_source_selection_preserves_foreign_keys_creation_replay_and_invoice_evidence(): void
    {
        $delivery = $this->documentDelivery();
        $line = $delivery->items()->first();
        $service = app(SalesInvoiceService::class);
        $identity = (string) Str::uuid7();
        $data = ['sales_order_id' => $delivery->salesOrder->uuid, 'invoice_date' => today()->toDateString(),
            'items' => [['sales_delivery_item_id' => $line->uuid, 'quantity' => '4']]];
        $invoice = $service->create($this->documentActor, $data, $identity);
        $this->assertSame((int) $delivery->sales_order_id, (int) $invoice->sales_order_id);
        $this->assertSame($line->id, $invoice->items()->first()->deliveryMatches()->first()->sales_delivery_item_id);
        $data['sales_order_id'] = $delivery->sales_order_id;
        $data['items'][0]['sales_delivery_item_id'] = $line->id;
        $this->assertSame($invoice->invoice_number, $service->create($this->documentActor, $data, $identity)->invoice_number);
        $this->assertSame(1, DocumentNumber::where('document_type', 'INV')->count());
        $invoice = $service->post($this->documentActor, $invoice, $service->revision($invoice));
        $attributes = $invoice->getAttributes();
        app(DocumentPresentation::class)->overview($invoice, $this->documentActor);
        $this->assertSame($attributes, $invoice->fresh()->getAttributes());
        $service->verifyPosted($invoice->fresh());
    }

    public function test_foreign_branch_sources_cannot_be_searched_selected_or_submitted(): void
    {
        $delivery = $this->documentDelivery();
        $foreign = Location::create(['name' => 'Foreign document branch', 'type' => 'branch', 'is_active' => true]);
        $actor = $this->scopedDocumentUser($foreign, SalesPermissions::all());
        $selectors = app(DocumentSelectors::class);
        $this->assertSame([], $selectors->orders($actor, 'invoice', 'Document Customer'));
        $this->assertNull($selectors->selectedLabel($actor, 'invoice', $delivery->salesOrder->uuid));
        $this->assertSame([], $selectors->lines($actor, 'return', $delivery->salesOrder->uuid));
        $before = DocumentNumber::count();
        try {
            app(SalesInvoiceService::class)->create($actor, ['sales_order_id' => $delivery->salesOrder->uuid, 'invoice_date' => today()->toDateString(),
                'items' => [['sales_delivery_item_id' => $delivery->items()->first()->uuid, 'quantity' => '1']]], (string) Str::uuid7());
            $this->fail('Foreign source was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, DocumentNumber::count());
    }

    public function test_legacy_uuid_references_get_explicit_locators_without_persisting_aliases_or_changing_records(): void
    {
        $legacy = SalesOrder::factory()->create(['location_id' => $this->documentBranch->id, 'order_number' => 'SO-'.Str::uuid7()]);
        $before = $legacy->refresh()->getAttributes();
        $label = $legacy->display_reference;
        $this->assertStringContainsString(__('documents.legacy_record', ['id' => $legacy->id]), $label);
        $this->assertFalse(DocumentPresentation::containsUuid($label));
        Livewire::test(ViewSalesOrder::class, ['record' => $legacy->uuid])->assertSuccessful()->assertSee($label)->assertSee($legacy->order_number);
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertFalse(DocumentNumber::where('document_uuid', $legacy->uuid)->exists());
    }

    public function test_arabic_and_english_overviews_render_real_statuses_tables_and_authorized_navigation(): void
    {
        $delivery = $this->documentDelivery();
        $order = $delivery->salesOrder->refresh();
        $this->assertSame(['draft', 'confirmed', 'partially_fulfilled', 'fulfilled'],
            app(DocumentPresentation::class)->overview($order, $this->documentActor)['steps']);
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $page = Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->assertSuccessful()
                ->assertSee('dir="'.$direction.'"', false)->assertSee($order->order_number)
                ->assertSee('erp-document-evidence', false)->assertSee(__('documents.fulfilled'));
            file_put_contents(base_path('../output/phase6-document-preview-'.$locale.'.html'), $page->html());
        }
        $links = app(DocumentPresentation::class)->links($order, $this->documentActor);
        $this->assertCount(1, $links);
        $this->assertStringContainsString($delivery->delivery_number, $links[0]['label']);
        $viewer = $this->scopedDocumentUser($this->documentBranch, [SalesPermissions::VIEW]);
        $this->assertSame([], app(DocumentPresentation::class)->links($order, $viewer));
    }

    public function test_supplier_invoice_reference_is_preserved_with_separate_warehouse_owned_erp_number(): void
    {
        $warehouse = Location::create(['name' => 'Bill warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $order = $this->documentPurchase($warehouse);
        $service = app(VendorBillService::class);
        $data = $service->prefill($this->documentActor, $order);
        $data['purchase_order_id'] = $order->uuid;
        $data['bill_number'] = 'SUP-EXTERNAL-001';
        $data['bill_date'] = today()->toDateString();
        $bill = $service->create($this->documentActor, $data);
        $this->assertSame('SUP-EXTERNAL-001', $bill->bill_number);
        $this->assertStringStartsWith($warehouse->code.'-BILL-', $bill->display_reference);
        $this->assertSame($warehouse->id, $bill->documentNumber->location_id);
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->assertSuccessful()
            ->assertSee($bill->display_reference)->assertSee('SUP-EXTERNAL-001')->assertSee($warehouse->name);
        $this->assertSame([], app(DocumentSelectors::class)->orders($this->documentActor, 'purchase', 'missing supplier'));
        $this->assertArrayHasKey($order->uuid, app(DocumentSelectors::class)->orders($this->documentActor, 'purchase', 'Document Supplier'));
    }

    public function test_selectors_exclude_fully_invoiced_and_fully_claimed_sources(): void
    {
        $delivery = $this->documentDelivery();
        $order = $delivery->salesOrder;
        $invoices = app(SalesInvoiceService::class);
        $invoice = $invoices->create($this->documentActor, ['sales_order_id' => $order->uuid, 'invoice_date' => today()->toDateString(),
            'items' => [['sales_delivery_item_id' => $delivery->items()->first()->uuid, 'quantity' => '10']]], (string) Str::uuid7());
        $invoices->post($this->documentActor, $invoice, $invoices->revision($invoice));
        $this->assertSame([], app(DocumentSelectors::class)->orders($this->documentActor, 'invoice', $order->order_number));
        $returns = app(SalesReturnService::class);
        $return = $returns->create($this->documentActor, ['sales_order_id' => $order->uuid, 'return_date' => today()->toDateString(),
            'items' => [['sales_delivery_item_id' => $delivery->items()->first()->uuid, 'quantity' => '10', 'disposition' => 'restock']]], (string) Str::uuid7());
        $this->assertArrayHasKey($order->uuid, app(DocumentSelectors::class)->orders($this->documentActor, 'return', $order->order_number));
        $manager = $this->scopedDocumentUser($this->documentBranch, SalesPermissions::all());
        app(SalesReturnApprovalService::class)->approve($manager, $return, $returns->revision($return));
        $this->assertSame([], app(DocumentSelectors::class)->orders($manager, 'return', $order->order_number));
    }

    public function test_document_search_is_bounded_and_does_not_query_per_order(): void
    {
        $this->documentPurchase(quantity: '100');
        for ($index = 0; $index < 55; $index++) {
            $order = app(SalesOrderService::class)->create($this->documentActor, ['location_id' => $this->documentBranch->id,
                'customer_name' => 'Search customer '.$index, 'items' => [['product_id' => $this->documentVariant->product_id,
                    'variant_id' => $this->documentVariant->id, 'description' => 'Search shirt', 'quantity' => '1', 'unit_price' => '10',
                    'discount_amount' => '0', 'tax_amount' => '0']]], (string) Str::uuid7());
            app(SalesOrderConfirmationService::class)->confirm($this->documentActor, $order);
        }
        DB::enableQueryLog();
        $results = app(DocumentSelectors::class)->orders($this->documentActor, 'delivery');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(50, $results);
        $this->assertLessThan(20, count($queries));
        foreach ($results as $label) {
            $this->assertFalse(DocumentPresentation::containsUuid($label));
        }
    }

    public function test_read_only_pages_remain_available_before_numbering_migration_without_silent_number_fallback(): void
    {
        $legacy = SalesOrder::factory()->create(['location_id' => $this->documentBranch->id, 'order_number' => 'SO-LEGACY-001']);
        $this->partialMock(DocumentNumberService::class,
            fn ($mock) => $mock->shouldReceive('installed')->andReturn(false));
        DB::enableQueryLog();
        Livewire::test(ViewSalesOrder::class, ['record' => $legacy->uuid])->assertSuccessful()->assertSee('SO-LEGACY-001');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, array_filter($queries, fn ($query) => str_contains($query['query'], 'document_numbers')));
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(DocumentNumberService::class)
            ->allocate($this->documentBranch->id, 'SO', (string) Str::uuid7()));
    }

    public function test_existing_posted_legacy_invoice_reference_and_signed_snapshot_are_preserved(): void
    {
        $delivery = $this->documentDelivery();
        $this->partialMock(DocumentNumberService::class,
            fn ($mock) => $mock->shouldReceive('allocate')->with($this->documentBranch->id, 'INV', \Mockery::type('string'))->once()->andReturn('INV-2026-000123'));
        $service = app(SalesInvoiceService::class);
        $invoice = $service->create($this->documentActor, ['sales_order_id' => $delivery->salesOrder->uuid, 'invoice_date' => today()->toDateString(),
            'items' => [['sales_delivery_item_id' => $delivery->items()->first()->uuid, 'quantity' => '2']]], (string) Str::uuid7());
        $invoice = $service->post($this->documentActor, $invoice, $service->revision($invoice));
        $before = $invoice->refresh()->getAttributes();
        app()->forgetInstance(DocumentNumberService::class);
        $this->assertSame('INV-2026-000123', $invoice->fresh()->display_reference);
        $this->assertFalse(DocumentNumber::where('document_uuid', $invoice->uuid)->exists());
        app(DocumentPresentation::class)->overview($invoice, $this->documentActor);
        $service->verifyPosted($invoice->fresh());
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(DocumentNumberService::class)->allocate($this->documentBranch->id, 'INV', $invoice->uuid));
    }

    public function test_line_search_batches_availability_and_variant_queries(): void
    {
        $delivery = $this->documentDelivery(10);
        $selectors = app(DocumentSelectors::class);
        DB::enableQueryLog();
        $selectors->lineDetail($this->documentActor, 'invoice', $delivery->salesOrder->uuid, $delivery->items()->first()->uuid);
        $single = count(DB::getQueryLog());
        DB::flushQueryLog();
        $lines = $selectors->lines($this->documentActor, 'invoice', $delivery->salesOrder->uuid);
        $multiple = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(10, $lines);
        $this->assertLessThanOrEqual($single + 2, $multiple);
    }
}
