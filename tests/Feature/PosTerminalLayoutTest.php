<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Filament\Pages\PosTerminal;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosPermissions;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Livewire\Attributes\Computed;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PosTerminalLayoutTest extends TestCase
{
    #[TestWith(['ar', 'rtl'])]
    #[TestWith(['en', 'ltr'])]
    #[TestWith(['ar', 'rtl', true])]
    #[TestWith(['en', 'ltr', true])]
    #[TestWith(['en', 'ltr', false, true])]
    #[TestWith(['en', 'ltr', false, false, false])]
    public function test_terminal_renders_existing_controls_in_both_directions_without_database_access(string $locale, string $direction, bool $checkout = false, bool $administration = false, bool $shiftAccess = true): void
    {
        $connection = new Connection(function (): never {
            throw new \LogicException('Layout checks cannot access a database.');
        });
        $resolver = new ConnectionResolver(['layout' => $connection]);
        $resolver->setDefaultConnection('layout');
        Model::setConnectionResolver($resolver);
        app()->setLocale($locale);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setRawAttributes(User::factory()->make(['id' => 4, 'name' => 'Cashier / أمين الصندوق', 'is_active' => true, 'must_change_password' => false])->getAttributes());
        $actor->shouldReceive('hasPanelPermissions')->andReturn($administration);
        $this->actingAs($actor);
        $this->mock(PosAccess::class)->shouldReceive('can')
            ->andReturnUsing(fn (User $user, string $permission): bool => $shiftAccess && $permission !== PosPermissions::RETURNS);
        $component = Livewire::test(PosTerminalLayoutFixture::class, ['checkout' => $checkout]);
        if (! $shiftAccess) {
            $component->assertForbidden();

            return;
        }
        $component->assertOk();
        $html = $component->html();
        $this->assertStringContainsString('dir="'.$direction.'"', $html);
        foreach (['data-pos-terminal', 'data-pos-shell-readiness', 'data-pos-catalog-readiness', 'data-pos-offline-sales-readiness',
            'data-pos-local', 'data-pos-scope', 'data-pos-sync-url', 'data-pos-csrf',
            'wire:model.live.debounce.300ms="search"', '$wire.addProduct(', '$wire.openCheckout()',
            'wire:click="changeQuantity(', 'wire:click="removeFromCart('] as $binding) {
            $this->assertStringContainsString($binding, $html);
        }
        $this->assertStringContainsString('class="fox-pos-navigation"', $html);
        if ($administration) {
            $this->assertStringContainsString('data-pos-administration', $html);
            $this->assertStringContainsString(route('filament.admin.pages.dashboard'), $html);
        } else {
            $this->assertStringNotContainsString('data-pos-administration', $html);
        }
        if ($shiftAccess) {
            $this->assertStringContainsString('data-pos-shift-link', $html);
        } else {
            $this->assertStringNotContainsString('data-pos-shift-link', $html);
        }
        if ($checkout) {
            foreach (['role="dialog"', 'wire:model.blur="cashReceived"', '$wire.checkout()'] as $binding) {
                $this->assertStringContainsString($binding, $html);
            }
        }
        $style = Vite::asset('resources/css/filament/admin/theme.css');
        File::ensureDirectoryExists(storage_path('framework/testing'));
        File::put(storage_path('framework/testing/pos-labels-'.$locale.'.json'), json_encode(__('pos.offline'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $suffix = $checkout ? '-checkout' : ($administration ? '-admin' : ($shiftAccess ? '' : '-restricted'));
        File::put(storage_path('framework/testing/pos-layout-'.$locale.$suffix.'.html'),
            '<!doctype html><html lang="'.$locale.'" dir="'.$direction.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="'.$style.'"></head><body class="fi-body fi-body-has-navigation fi-body-has-topbar"><nav class="fi-topbar">Admin topbar</nav><div class="fi-layout"><div><aside class="fi-sidebar fi-main-sidebar">Admin sidebar</aside></div><div class="fi-main-ctn" style="display:flex;opacity:1"><main class="fi-main">'.$html.'</main></div></div></body></html>');
    }

    #[TestWith([false, true, false, false])]
    #[TestWith([true, true, false, true])]
    #[TestWith([true, false, false, false])]
    #[TestWith([true, true, true, false])]
    public function test_administration_navigation_requires_current_panel_access(bool $panelAccess, bool $active, bool $passwordChange, bool $visible): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->forceFill(['id' => 4, 'is_active' => $active, 'must_change_password' => $passwordChange]);
        $actor->shouldReceive('hasPanelPermissions')->andReturn($panelAccess);
        $this->actingAs($actor);
        $page = new PosTerminal;
        $url = $page->administrationUrl();
        if ($visible) {
            $this->assertSame(route('filament.admin.pages.dashboard'), $url);
        } else {
            $this->assertNull($url);
        }
    }
}

/** View-only fixture. Production checkout methods are never invoked or replaced. */
class PosTerminalLayoutFixture extends PosTerminal
{
    public function mount(bool $checkout = false): void
    {
        $this->locationId = 1;
        $this->posSessionId = 3;
        $this->showCheckout = $checkout;
        $this->tenders = [5 => ['amount' => '100.0000', 'external_reference' => '']];
        $this->cart = [21 => ['quantity' => '1.0000', 'discount_id' => null]];
        $this->cartQuote = ['items' => [['variant_id' => 21, 'image' => '', 'product_name' => 'قميص Cotton shirt — long mixed product name',
            'variant_name' => 'أزرق / Blue · M', 'unit_price' => '100.0000', 'discounts' => [], 'discount_id' => null, 'line_total' => '100.0000', 'quantity' => '1.0000']],
            'subtotal' => '100.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000', 'total_amount' => '100.0000'];
    }

    #[Computed]
    public function currentSession(): ?PosSession
    {
        $session = (new PosSession)->forceFill(['id' => 3, 'session_number' => 'SHIFT-001']);
        $session->setRelation('location', (new Location)->forceFill(['name' => 'فرع القاهرة / Cairo']));
        $session->setRelation('posDevice', (new PosDevice)->forceFill(['device_code' => 'POS-002']));

        return $session;
    }

    #[Computed]
    public function offlineScope(): ?array
    {
        return ['branch_id' => 1, 'device_id' => 2, 'session_id' => 3, 'cashier_id' => 4];
    }

    #[Computed]
    public function filters(): array
    {
        return ['categories' => [], 'brands' => [], 'sizes' => [], 'colors' => []];
    }

    #[Computed]
    public function products(): array
    {
        $items = [];
        for ($id = 1; $id <= 8; $id++) {
            $items[] = ['id' => $id, 'name' => 'قميص Cotton shirt — long mixed product name '.$id,
                'sku' => 'SHIRT-'.$id, 'image' => '', 'discounts' => [], 'brand_icon' => null, 'brand' => 'FOX & HEMA', 'category' => 'Clothing',
                'variants' => [['id' => $id + 20, 'image' => '', 'sku' => 'SKU-'.$id, 'price' => '100.0000', 'available' => $id === 8 ? '0' : '10',
                    'label' => 'Blue / أزرق · M', 'size' => 'M', 'color' => 'Blue']]];
        }

        return ['items' => $items, 'total' => 8, 'page' => 1, 'last_page' => 1];
    }

    #[Computed]
    public function recentTransactions(): array
    {
        return [];
    }

    #[Computed]
    public function receipt(): ?array
    {
        return null;
    }

    #[Computed]
    public function canSelectCustomer(): bool
    {
        return false;
    }

    #[Computed]
    public function checkoutMethods(): array
    {
        return [['id' => 5, 'name' => 'Cash / نقدي', 'account_name' => 'Drawer / الخزنة', 'type' => 'cash', 'requires_reference' => false]];
    }
}
