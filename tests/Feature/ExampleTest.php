<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_guests_get_the_login_action_in_both_languages(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $this->withSession(['locale' => $locale])->get('/')
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('href="'.route('filament.admin.auth.login').'"', false)
                ->assertDontSee('href="'.route('filament.admin.pages.dashboard').'"', false)
                ->assertSee(__('landing.login'))
                ->assertSee(__('landing.headline'))
                ->assertSee(__('landing.preview_caption'))
                ->assertDontSee('landing.modules.');
        }
    }

    public function test_authenticated_users_get_the_existing_dashboard_destination(): void
    {
        $user = User::factory()->make(['id' => 123, 'must_change_password' => false]);
        $this->actingAs($user);

        foreach (['ar', 'en'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/')
                ->assertOk()
                ->assertSee('href="'.route('filament.admin.pages.dashboard').'"', false)
                ->assertSee(__('landing.dashboard'))
                ->assertDontSee('href="'.route('filament.admin.auth.login').'"', false);
        }
    }

    public function test_landing_language_switch_keeps_the_public_destination(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $this->post(route('locale.update', $locale), ['return_to' => '/'])
                ->assertRedirect('/')
                ->assertSessionHas('locale', $locale)
                ->assertCookie('locale', $locale);

            $this->get('/')
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('action="'.route('locale.update', $locale).'"', false)
                ->assertSee('name="return_to" value="/"', false)
                ->assertSee(__('landing.headline'));
        }
    }
}
