<?php

namespace Tests\Unit;

use App\Modules\Payments\Filament\Pages\CustomerPaymentHistory;
use App\Modules\Payments\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Modules\Payments\Filament\Resources\CustomerRefunds\CustomerRefundResource;
use App\Modules\Payments\Filament\Resources\Customers\CustomerResource;
use App\Modules\Payments\Filament\Resources\PaymentAllocations\PaymentAllocationResource;
use App\Modules\Payments\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\PaymentMoney;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentsLocalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->beforeExecuting(function (): never {
            throw new \LogicException('Localization tests must not execute SQL or migrations.');
        });
    }

    public function test_payment_catalogs_have_matching_complete_arabic_and_english_entries(): void
    {
        foreach (['payments', 'payment_methods', 'vendor_payments'] as $catalog) {
            $english = Arr::dot(require lang_path('en/'.$catalog.'.php'));
            $arabic = Arr::dot(require lang_path('ar/'.$catalog.'.php'));
            $this->assertSame(array_keys($english), array_keys($arabic));
            foreach ($english as $key => $value) {
                $this->assertNotSame('', $value, $key);
                $this->assertNotSame('', $arabic[$key], $key);
                $this->assertTrue(Lang::has($catalog.'.'.$key, 'en', false), $key);
                $this->assertTrue(Lang::has($catalog.'.'.$key, 'ar', false), $key);
            }
        }
    }

    public function test_payment_resource_names_and_navigation_are_localized_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ([
                [CustomerResource::class, 'Customer', 'Customers'],
                [CustomerPaymentResource::class, 'Customer payment', 'Customer payments'],
                [CustomerRefundResource::class, 'Customer refund', 'Customer refunds'],
                [PaymentAllocationResource::class, 'Payment allocation', 'Payment allocations'],
            ] as [$resource,$singular,$plural]) {
                $this->assertSame(__($singular), $resource::getModelLabel());
                $this->assertSame(__($plural), $resource::getPluralModelLabel());
                $this->assertSame(__($resource === CustomerResource::class ? 'Sales' : 'Payments'), $resource::getNavigationGroup());
            }
            $this->assertSame(__('payment_methods.plural'), PaymentMethodResource::getNavigationLabel());
        }
    }

    public function test_all_static_payments_interface_strings_have_translations_without_fallback(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules/Payments')));
        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php') || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Database'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            preg_match_all("/__\\('([^']+)'\\)/", file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $key) {
                foreach (['ar', 'en'] as $locale) {
                    $this->assertTrue(Lang::has($key, $locale, false), $file->getFilename().': '.$key.' ('.$locale.')');
                }
            }
        }
    }

    public function test_payment_errors_and_validation_attributes_are_translated(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            try {
                (new PaymentMoney)->normalize('invalid');
                $this->fail('Invalid amount accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame(__('Use a positive plain decimal within DECIMAL(20,4); rounding inputs is forbidden.'), $exception->errors()['amount'][0]);
                if ($locale === 'ar') {
                    $this->assertStringContainsString('أدخل مبلغاً', $exception->errors()['amount'][0]);
                }
            }
            try {
                app(CustomerAccess::class)->key('invalid-key');
                $this->fail('Invalid key accepted.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString(__('payments.validation_attributes.operation_key'), $exception->errors()['operation_key'][0]);
            }
        }
    }

    public function test_history_headers_and_dynamic_status_labels_are_available_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['Date', 'Type', 'Code', 'Customer', 'Status', 'Method', 'Reference', 'Related document', 'Amount', 'Running balance'] as $label) {
                $this->assertTrue(Lang::has($label, $locale, false), $label);
            }
            foreach (['draft', 'posted', 'pending', 'failed', 'cancelled', 'unpaid', 'partial', 'settled'] as $status) {
                $this->assertTrue(Lang::has('payments.status.'.$status, $locale, false), $status);
            }
        }
    }

    public function test_retail_payment_navigation_and_removed_standalone_routes_are_consistent(): void
    {
        $this->assertFalse(CustomerPaymentResource::shouldRegisterNavigation());
        $this->assertSame([], CustomerPaymentResource::getNavigationItems());
        $this->assertFalse(CustomerPaymentResource::canCreate());
        $this->assertSame(['index', 'view'], array_keys(CustomerPaymentResource::getPages()));
        foreach ([CustomerRefundResource::class, PaymentAllocationResource::class] as $resource) {
            $this->assertFalse($resource::isDiscovered());
            $this->assertFalse($resource::shouldRegisterNavigation());
            $this->assertNotContains($resource, Filament::getPanel('admin')->getResources());
        }
        $this->assertFalse(CustomerPaymentHistory::shouldRegisterNavigation());
        foreach (['customer-payments.create', 'customer-payments.advances', 'customer-refunds.index', 'customer-refunds.create', 'customer-refunds.view', 'payment-allocations.index'] as $route) {
            $this->assertFalse(Route::has('filament.admin.resources.'.$route), $route);
        }
        foreach (['customer-payments.view', 'customers.index', 'payment-methods.index', 'sales-invoices.view', 'sales-credit-notes.view'] as $route) {
            $this->assertTrue(Route::has('filament.admin.resources.'.$route), $route);
        }
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(__('Sales'), CustomerResource::getNavigationGroup());
            $this->assertSame(__('payments.settings'), PaymentMethodResource::getNavigationGroup());
        }
    }
}
