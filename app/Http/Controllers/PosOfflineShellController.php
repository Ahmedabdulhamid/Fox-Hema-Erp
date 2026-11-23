<?php

namespace App\Http\Controllers;

use App\Http\Middleware\BindPosOfflineContext;
use App\Modules\AccessManagement\Filament\Pages\Dashboard;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\POS\Filament\Resources\PosSessions\PosSessionResource;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosPermissions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PosOfflineShellController extends Controller
{
    public function worker(Request $request): Response
    {
        $session = $this->authorizedSession($request);
        abort_if(is_file(public_path('hot')), 503, 'Build the POS assets before installing the offline shell.');
        $scope = [
            'branch_id' => (int) $session->location_id,
            'device_id' => (int) $session->pos_device_id,
            'session_id' => (int) $session->id,
            'cashier_id' => (int) $request->user()->id,
        ];
        $actor = $request->user();
        $administrationUrl = $actor->is_active && ! $actor->must_change_password
            && $actor->hasPanelPermissions() && Dashboard::canAccess()
            ? Dashboard::getUrl(panel: 'admin')
            : null;
        $presentation = [
            'branch_name' => $session->location?->name ?? '—',
            'cashier_name' => $actor->name ?? '—',
            'device_code' => $session->posDevice?->device_code ?? '—',
            'shift_code' => $session->display_code,
            'opened_at' => $session->opened_at?->format('Y-m-d H:i') ?? '—',
            'status' => __('pos.status.'.$session->status),
            'opening_cash' => number_format((float) $session->opening_cash, 2).' '.__('pos.currency'),
            'can_view_shifts' => PosSessionResource::canViewAny(),
            'administration_url' => $administrationUrl,
        ];
        $configuration = $this->configuration($scope, $presentation,
            app(BindPosOfflineContext::class)->bind($request, $scope));

        return response(str_replace('__POS_SHELL_CONFIG__', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            file_get_contents(resource_path('js/pos-service-worker.js'))), 200, [
                'Content-Type' => 'text/javascript; charset=utf-8',
                'Cache-Control' => 'private, no-store',
                'Service-Worker-Allowed' => $configuration['terminal'],
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    private function authorizedSession(Request $request): PosSession
    {
        $locationId = app(LocationContext::class)->initializeFor($request->user());
        abort_if($locationId === null, 403);
        app(PosAccess::class)->authorize($request->user(), PosPermissions::VIEW, $locationId);
        app(PosAccess::class)->authorize($request->user(), PosPermissions::CHECKOUT, $locationId);

        return PosSession::query()
            ->with(['location:id,name,code', 'posDevice:id,device_code'])
            ->where('location_id', $locationId)->where('opened_by', $request->user()->id)
            ->where('status', 'open')->whereNull('closing_started_at')->whereNull('closed_at')
            ->whereHas('posDevice', fn ($query) => $query->where('is_active', true)->whereNull('revoked_at'))
            ->firstOrFail();
    }

    /** @return array{version: string, terminal: string, shell: string, assets: array<string, string>} */
    public function configuration(array $scope, array $presentation = [], ?string $context = null): array
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $assets = [];
        $visitFile = function (string $file) use (&$visitFile, &$assets): void {
            abort_unless(preg_match('~^assets/[a-zA-Z0-9_./-]+\.(js|css|woff2?|ttf|svg|png|webp)$~', $file)
                && ! str_contains($file, '..'), 503);
            $path = public_path('build/'.$file);
            abort_unless(is_file($path), 503, 'Incomplete POS build.');
            $url = '/build/'.$file;
            if (isset($assets[$url])) {
                return;
            }
            $assets[$url] = hash_file('sha256', $path);
            if (str_ends_with($file, '.css')) {
                preg_match_all('~url\([\x22\x27]?([^\s)\x22\x27]+)~', file_get_contents($path), $matches);
                foreach ($matches[1] as $dependency) {
                    if (str_starts_with($dependency, 'data:')) {
                        continue;
                    }
                    $visitFile(str_starts_with($dependency, '/build/') ? substr($dependency, 7) : 'assets/'.basename($dependency));
                }
            }
        };
        $visited = [];
        $visitEntry = function (string $entry) use (&$visitEntry, &$visited, $manifest, $visitFile): void {
            if (isset($visited[$entry])) {
                return;
            }
            $visited[$entry] = true;
            abort_unless(isset($manifest[$entry]), 503, 'Missing POS build entry.');
            $chunk = $manifest[$entry];
            $visitFile($chunk['file']);
            foreach (array_merge($chunk['css'] ?? [], $chunk['assets'] ?? []) as $file) {
                $visitFile($file);
            }
            foreach (array_merge($chunk['imports'] ?? [], $chunk['dynamicImports'] ?? []) as $dependency) {
                $visitEntry($dependency);
            }
        };
        foreach (['resources/js/pos-shell.js', 'resources/js/pos-offline.js', 'resources/css/filament/admin/theme.css'] as $entry) {
            $visitEntry($entry);
        }
        ksort($assets);
        $shell = view('pos.offline-shell', [
            'stylesheet' => '/build/'.$manifest['resources/css/filament/admin/theme.css']['file'],
            'script' => '/build/'.$manifest['resources/js/pos-shell.js']['file'],
            'scope' => $scope,
            'context' => $context,
            'presentation' => $presentation,
        ])->render();

        return ['version' => hash('sha256', json_encode($assets).$shell.file_get_contents(resource_path('js/pos-service-worker.js'))),
            'terminal' => route('filament.admin.pages.pos-terminal', absolute: false), 'shell' => $shell, 'assets' => $assets];
    }
}
