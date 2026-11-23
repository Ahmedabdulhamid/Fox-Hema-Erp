<?php

use App\Http\Middleware\BeginAuthorizationRequest;
use App\Http\Middleware\BindPosOfflineContext;
use App\Http\Middleware\SetLocale;
use App\Modules\AccessManagement\Http\Middleware\EnsureModuleActive;
use App\Modules\AccessManagement\Http\Middleware\ResetLocationPermissionContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('backups:automation')->everyMinute()->withoutOverlapping(10);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['module.active' => EnsureModuleActive::class]);
        $middleware->web(prepend: [BeginAuthorizationRequest::class]);
        $middleware->web(append: [SetLocale::class, ResetLocationPermissionContext::class, BindPosOfflineContext::class]);
        $middleware->encryptCookies(except: ['fox_pos_context']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if (in_array($status, [403, 404, 419, 422, 429, 500, 503], true)
                && ! $request->expectsJson() && ! $request->is('api/*', 'webhook/*', 'webhooks/*')
                && ! $request->hasHeader('X-Livewire')
                && str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
                $response->setContent(view('errors.layout', ['code' => $status])->render());
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
