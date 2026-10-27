<?php

namespace App\Modules\Products\Filament\Resources\Products\RelationManagers;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Products\Schemas\ProductImageFields;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductImageManager;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Images');
    }

    protected static function getModelLabel(): ?string
    {
        return __('Image');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ProductImageFields::upload()->required(fn (string $operation): bool => $operation === 'create'),
            Select::make('product_variant_id')->label(__('Variant (optional)'))->options(fn (): array => $this->getOwnerRecord()->variants()->with(['size', 'color'])->get()
                ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => view('components.variant-summary', ['variant' => $variant])->render()])->all())->searchable()->allowHtml(),
            ...ProductImageFields::metadata(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('alt_text')->defaultSort('sort_order')->modifyQueryUsing(fn ($query) => $query->with(['variant.color', 'variant.size']))->columns([
            ImageColumn::make('path')->label(__('Image'))->disk(fn (ProductImage $record): string => $record->disk), TextColumn::make('variant_label')->label(__('Variant'))->state(__('General product'))->formatStateUsing(fn (ProductImage $record) => $record->variant ? view('components.variant-summary', ['variant' => $record->variant]) : __('General product')), TextColumn::make('variant.size.name')->toggleable(isToggledHiddenByDefault: true)->label(__('Size'))->placeholder('—'), TextColumn::make('variant.color.name')->toggleable(isToggledHiddenByDefault: true)->label(__('Color'))->placeholder('—'), TextColumn::make('alt_text')->label(__('Alternative text')), IconColumn::make('is_primary')->label(__('Primary image'))->boolean(), TextColumn::make('sort_order')->label(__('Sort order'))->sortable(),
        ])->headerActions([
            CreateAction::make()->using(fn (array $data): ProductImage => ProductMutation::run(fn () => app(ProductImageManager::class)->save(auth()->user(), $this->getOwnerRecord(), $data), $this->getMountedActionSchema()?->getStatePath())),
        ])->recordActions([
            EditAction::make()->mutateRecordDataUsing(fn (array $data): array => Arr::only($data, ['product_variant_id', 'alt_text', 'sort_order', 'is_primary']))->using(fn (ProductImage $record, array $data): ProductImage => ProductMutation::run(fn () => app(ProductImageManager::class)->save(auth()->user(), $this->getOwnerRecord(), $data, $record), $this->getMountedActionSchema()?->getStatePath())),
            Action::make('make_primary')->label(__('Make primary'))->requiresConfirmation()->authorize(fn (ProductImage $record): bool => ! $this->isReadOnly() && auth()->user()->can('update', $record))->action(fn (ProductImage $record): ProductImage => app(ProductImageManager::class)->save(auth()->user(), $this->getOwnerRecord(), ['is_primary' => true], $record)),
            DeleteAction::make()->using(fn (ProductImage $record): bool => ProductMutation::run(fn () => app(ProductImageManager::class)->delete(auth()->user(), $this->getOwnerRecord(), $record))),
        ]);
    }
}
