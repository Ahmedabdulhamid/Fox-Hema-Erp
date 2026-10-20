<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrandedErrorPagesTest extends TestCase
{
    public static function pages(): array
    {
        $cases = [];
        foreach ([403, 404, 419, 422, 429, 500, 503] as $code) {
            foreach (['en', 'ar'] as $locale) {
                $cases[$code.'-'.$locale] = [$code, $locale];
            }
        }

        return $cases;
    }

    #[DataProvider('pages')]
    public function test_full_page_errors_preserve_status_and_hide_details(int $code, string $locale): void
    {
        app()->setLocale($locale);
        config(['app.debug' => true]);
        Route::get('/_test/error', fn () => abort($code, 'SECRET SQL /private/file.php password=hidden', ['Retry-After' => '31']));
        $response = $this->get('/_test/error');
        $response->assertStatus($code)->assertSee('Fox &amp; Hema', false)->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
            ->assertDontSee('SECRET')->assertDontSee('/private/file.php')->assertDontSee('password=hidden');
        $this->assertSame('31', $response->headers->get('Retry-After'));
        if (getenv('EXPORT_BRANDED_ERROR_PREVIEWS') === '1') {
            $directory = base_path('../output/catalog-error-previews');
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$code.'-'.$locale.'.html', $response->getContent());
        }
    }

    public function test_json_and_livewire_errors_keep_their_existing_contracts(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/error', fn () => abort(403, 'Permission denied'));
        $this->getJson('/_test/error')->assertForbidden()->assertJsonPath('message', 'Permission denied')->assertDontSee('Fox &amp; Hema', false);
        $this->get('/_test/error', ['X-Livewire' => 'true', 'Accept' => 'application/json'])->assertForbidden()->assertJsonPath('message', 'Permission denied');
        $this->get('/api/missing')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_normal_validation_still_redirects_or_returns_field_errors(): void
    {
        Route::post('/_test/validation', fn () => throw ValidationException::withMessages(['quantity' => 'Invalid quantity']))->middleware('web');
        $this->from('/')->post('/_test/validation')->assertRedirect('/')->assertSessionHasErrors('quantity');
        $this->postJson('/_test/validation')->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_server_failure_is_branded_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        Route::get('/_test/failure', fn () => throw new \RuntimeException('SECRET internal database connection failed'));
        $this->get('/_test/failure')->assertStatus(500)->assertSee('Fox &amp; Hema', false)->assertDontSee('SECRET');
    }

    public function test_templates_need_no_database_and_never_resubmit_requests(): void
    {
        DB::shouldReceive('connection')->never();
        foreach ([500, 503, 419] as $code) {
            $html = view('errors.layout', ['code' => $code])->render();
            $this->assertStringContainsString('Fox &amp; Hema', $html);
            $this->assertStringNotContainsString('<form', $html);
            $this->assertStringNotContainsString('location.reload', $html);
        }
    }

    public function test_unknown_urls_honor_the_locale_cookie_without_web_middleware(): void
    {
        app()->setLocale('ar');
        $this->withCookie('locale', 'en')->get('/_unknown/locale-test')->assertNotFound()->assertSee('lang="en"', false);
        app()->setLocale('en');
        $this->withCookie('locale', 'ar')->get('/_unknown/locale-test')->assertNotFound()->assertSee('lang="ar"', false);
    }

    public function test_back_links_cannot_navigate_to_an_external_origin(): void
    {
        foreach (['https://evil.example/path', url('/').'/\\evil.example'] as $referer) {
            $this->get('/_unknown/back-test', ['Referer' => $referer])->assertNotFound()->assertDontSee('evil.example');
        }
    }
}
