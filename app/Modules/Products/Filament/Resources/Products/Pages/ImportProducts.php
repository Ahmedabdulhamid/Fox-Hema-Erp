<?php

namespace App\Modules\Products\Filament\Resources\Products\Pages;

use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Services\ProductImportAccess;
use App\Modules\Products\Services\ProductImportManager;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class ImportProducts extends Page
{
    use WithFileUploads, WithPagination;

    protected static string $resource = ProductResource::class;

    protected string $view = 'products.import-products';

    public mixed $workbook = null;

    public array $images = [];

    #[Locked]
    public ?int $importId = null;

    public static function canAccess(array $parameters = []): bool
    {
        return app(ProductImportAccess::class)->allows(auth()->user());
    }

    public function getTitle(): string
    {
        return __('product_import.title');
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function removeWorkbook(): void
    {
        app(ProductImportAccess::class)->authorize(auth()->user());
        $this->reset('workbook');
        $this->resetValidation('workbook');
    }

    public function removeImage(int $index): void
    {
        app(ProductImportAccess::class)->authorize(auth()->user());
        unset($this->images[$index]);
        $this->images = array_values($this->images);
        $this->resetValidation('images');
    }

    public function newUpload(): void
    {
        $import = $this->selected();
        if ($import !== null && $import->accepted_at === null && in_array($import->status, ['uploaded', 'ready', 'paused', 'failed'], true)) {
            app(ProductImportManager::class)->discard(auth()->user(), $import);
        }
        $this->reset('workbook', 'images', 'importId');
        $this->resetValidation();
        $this->resetPage('entriesPage');
    }

    public function mount(): void
    {
        app(ProductImportAccess::class)->authorize(auth()->user());
    }

    public function prepare(): void
    {
        app(ProductImportAccess::class)->authorize(auth()->user());
        $this->validate(['workbook' => ['required', 'file', 'max:10240'], 'images' => ['array', 'max:500'], 'images.*' => ['file', 'max:5120']]);
        $import = app(ProductImportManager::class)->submit(auth()->user(), $this->workbook, $this->images);
        $this->importId = $import->id;
        $this->reset('workbook', 'images');
        $this->resetPage('entriesPage');
    }

    public function showImport(int $id): void
    {
        $import = ProductImport::findOrFail($id);
        app(ProductImportAccess::class)->authorize(auth()->user(), $import);
        $this->importId = $id;
        $this->resetPage('entriesPage');
    }

    private function selected(): ?ProductImport
    {
        app(ProductImportAccess::class)->authorize(auth()->user());
        if ($this->importId === null) {
            return null;
        }
        $import = ProductImport::findOrFail($this->importId);
        app(ProductImportAccess::class)->authorize(auth()->user(), $import);

        return $import;
    }

    public function startImport(): void
    {
        app(ProductImportManager::class)->start(auth()->user(), $this->selected() ?? abort(404));
    }

    public function retryImport(): void
    {
        app(ProductImportManager::class)->retry(auth()->user(), $this->selected() ?? abort(404));
    }

    public function discardImport(): void
    {
        app(ProductImportManager::class)->discard(auth()->user(), $this->selected() ?? abort(404));
    }

    protected function getViewData(): array
    {
        $import = $this->selected();
        $history = ProductImport::query();
        if (! auth()->user()->isGlobalSuperAdmin()) {
            $history->where('actor_id', auth()->id())->where('location_id', getPermissionsTeamId())->where('global_scope', false);
        }
        $entries = $import?->entries()->orderBy('id')->paginate(20, pageName: 'entriesPage');
        $products = Product::whereIn('id', $entries?->getCollection()->pluck('result.product_id')->filter()->all() ?? [])->get()->keyBy('id');
        $summary = $import?->entries()->selectRaw('status, COUNT(*) AS total, SUM(source_rows) AS source_rows, SUM(variant_count) AS variants, SUM(image_count) AS images')
            ->groupBy('status')->get()->keyBy('status') ?? collect();

        return ['import' => $import, 'entries' => $entries, 'products' => $products,
            'counts' => $summary->pluck('total', 'status')->all(), 'summary' => $summary,
            'history' => $history->latest('id')->paginate(10, pageName: 'historyPage')];
    }
}
