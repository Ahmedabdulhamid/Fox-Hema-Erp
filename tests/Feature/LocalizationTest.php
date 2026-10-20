<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Filament\Widgets\AccessStats;
use App\Modules\AccessManagement\Filament\Widgets\DashboardHero;
use App\Modules\AccessManagement\Filament\Widgets\LocationsOverview;
use App\Modules\AccessManagement\Filament\Widgets\RecentSecurityEvents;
use App\Modules\AccessManagement\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_arabic_is_the_default_on_public_and_login_pages(): void
    {
        $this->assertSame('ar', config('app.locale'));
        $this->get('/')->assertOk()->assertSee('dir="rtl"', false)->assertSee('العربية');
        $this->get('/admin/login')->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
    }

    public function test_switching_languages_is_saved_and_renders_the_selected_direction(): void
    {
        $this->post('/language/en', ['return_to' => '/admin/login'])
            ->assertRedirect('/admin/login')->assertSessionHas('locale', 'en')->assertCookie('locale', 'en');
        $this->get('/admin/login')->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false)->assertSee('Sign in');

        $this->post('/language/ar', ['return_to' => '/'])->assertRedirect('/')->assertSessionHas('locale', 'ar');
        $this->get('/admin/login')->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
    }

    public function test_language_cookie_is_used_in_a_new_session_and_invalid_preferences_fall_back_to_arabic(): void
    {
        $this->withCookie('locale', 'en')->get('/admin/login')->assertOk()->assertSee('lang="en"', false);
        $this->withSession(['locale' => 'invalid'])->get('/admin/login')->assertOk()->assertSee('lang="ar"', false);
    }

    public function test_unsupported_languages_and_external_return_urls_are_rejected(): void
    {
        $this->post('/language/fr')->assertNotFound()->assertSessionMissing('locale');
        foreach (['https://example.com', '//example.com', '/\\example.com', ['invalid']] as $returnTo) {
            $this->post('/language/en', ['return_to' => $returnTo])->assertRedirect('/admin');
        }
    }

    public static function locales(): array
    {
        return [
            'arabic' => ['ar', 'rtl', 'المستخدمون والصلاحيات', 'الصلاحيات المتاحة'],
            'english' => ['en', 'ltr', 'Users & Permissions', 'Available Permissions'],
        ];
    }

    #[DataProvider('locales')]
    public function test_admin_pages_and_password_change_render_in_both_languages(string $locale, string $direction, string $group, string $permissions): void
    {
        config()->set('access-management.seed_admin', [
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'a-secure-test-password',
        ]);
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($user);

        $this->withSession(['locale' => $locale]);
        foreach (['/admin', '/admin/profile', '/admin/users', '/admin/users/create', '/admin/roles', '/admin/roles/create', '/admin/locations', '/admin/locations/create', '/admin/security-events/auth-events', '/admin/sessions/database-sessions'] as $url) {
            $this->get($url)->assertOk()->assertSee('dir="'.$direction.'"', false)->assertSee($group);
        }
        $this->get('/admin/roles/create')->assertOk()->assertSee($permissions);
        $this->get('/admin/password/change')->assertOk()->assertSee('dir="'.$direction.'"', false);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::withoutLazyLoading();
        Livewire::test(DashboardHero::class)->assertSee(__('Welcome back, :name 👋', ['name' => 'Admin']))->assertSee(__('All locations'));
        Livewire::test(AccessStats::class)->assertSee(__('Total Users'))->assertSee(__(':count active users', ['count' => 1]));
        Livewire::test(RecentSecurityEvents::class)->assertSee(__('Recent Security Events'));
        Livewire::test(LocationsOverview::class)->assertSee(__('No locations configured.'));
    }

    public function test_validation_and_authentication_messages_follow_the_locale(): void
    {
        $this->get('/admin/login');
        $this->assertSame('حقل البريد الإلكتروني مطلوب.', Validator::make([], ['email' => 'required'])->errors()->first('email'));
        $this->assertSame('بيانات تسجيل الدخول غير صحيحة.', __('auth.failed'));

        $this->withSession(['locale' => 'en'])->get('/admin/login');
        $this->assertSame('The email field is required.', Validator::make([], ['email' => 'required'])->errors()->first('email'));
        $this->assertSame('These credentials do not match our records.', __('auth.failed'));
    }
}
