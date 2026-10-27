<?php

namespace App\Modules\Payments\Filament\Resources\PaymentMethods;

use App\Modules\Payments\Filament\PaymentMethodMutation;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentsAccess;
use App\Modules\Payments\Services\PaymentsPermissions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('payments.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('payment_methods.plural');
    }

    public static function getModelLabel(): string
    {
        return __('payment_methods.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment_methods.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        app(PaymentsAccess::class)->authorize(auth()->user(), PaymentsPermissions::VIEW);

        return parent::getEloquentQuery();
    }

    public static function types(): array
    {
        return collect(PaymentMethod::TYPES)->mapWithKeys(fn (string $type): array => [$type => __('payment_methods.types.'.$type)])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('payment_methods.fields.name'))->validationAttribute(__('payment_methods.fields.name'))
                ->helperText(__('payment_methods.name_help'))->required()->maxLength(150),
            Select::make('type')->label(__('payment_methods.fields.type'))->validationAttribute(__('payment_methods.fields.type'))
                ->options(static::types())->required()->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create')
                ->helperText(__('payment_methods.type_help')),
            Toggle::make('is_active')->label(__('payment_methods.fields.is_active'))->default(true),
            Toggle::make('requires_reference')->label(__('payment_methods.fields.requires_reference'))->default(false),
            TextInput::make('sort_order')->label(__('payment_methods.fields.sort_order'))->validationAttribute(__('payment_methods.fields.sort_order'))
                ->integer()->minValue(0)->maxValue(4294967295)->default(0)->required(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label(__('payment_methods.fields.code'))->copyable(),
            TextEntry::make('name')->label(__('payment_methods.fields.name')),
            TextEntry::make('type')->label(__('payment_methods.fields.type'))->formatStateUsing(fn (string $state): string => __('payment_methods.types.'.$state)),
            TextEntry::make('is_active')->label(__('payment_methods.fields.is_active'))->badge()
                ->formatStateUsing(fn (bool $state): string => __('payment_methods.status.'.($state ? 'active' : 'inactive'))),
            TextEntry::make('requires_reference')->label(__('payment_methods.fields.requires_reference'))
                ->formatStateUsing(fn (bool $state): string => __('payment_methods.'.($state ? 'yes' : 'no'))),
            TextEntry::make('sort_order')->label(__('payment_methods.fields.sort_order')),
        ]);
    }

    public static function statusAction(bool $active): Action
    {
        return Action::make($active ? 'activate' : 'deactivate')->label(__('payment_methods.actions.'.($active ? 'activate' : 'deactivate')))
            ->authorize('update')->visible(fn (PaymentMethod $record): bool => $record->is_active !== $active)
            ->requiresConfirmation()->modalDescription(__('payment_methods.status_help'))
            ->action(function (PaymentMethod $record, $livewire) use ($active): void {
                PaymentMethodMutation::run(fn () => app(PaymentMethods::class)->save(auth()->user(), ['is_active' => $active], $record));
                Notification::make()->title(__('payment_methods.notifications.updated'))->success()->send();
                if ($livewire instanceof ViewRecord) {
                    $livewire->redirect(static::getUrl('view', ['record' => $record]));
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label(__('payment_methods.fields.code'))->searchable()->sortable(),
            TextColumn::make('name')->label(__('payment_methods.fields.name'))->searchable()->sortable(),
            TextColumn::make('type')->label(__('payment_methods.fields.type'))->formatStateUsing(fn (string $state): string => __('payment_methods.types.'.$state))->sortable(),
            TextColumn::make('is_active')->label(__('payment_methods.fields.is_active'))->badge()->sortable()
                ->formatStateUsing(fn (bool $state): string => __('payment_methods.status.'.($state ? 'active' : 'inactive'))),
            IconColumn::make('requires_reference')->label(__('payment_methods.fields.requires_reference'))->boolean(),
            TextColumn::make('sort_order')->label(__('payment_methods.fields.sort_order'))->sortable(),
        ])->filters([
            SelectFilter::make('type')->label(__('payment_methods.fields.type'))->options(static::types()),
            TernaryFilter::make('is_active')->label(__('payment_methods.fields.is_active'))
                ->placeholder(__('payment_methods.status.all'))->trueLabel(__('payment_methods.status.active'))->falseLabel(__('payment_methods.status.inactive')),
        ])->recordActions([
            ViewAction::make()->label(__('payment_methods.actions.view')),
            EditAction::make()->label(__('payment_methods.actions.edit')),
            static::statusAction(true), static::statusAction(false),
        ])->toolbarActions([])->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPaymentMethods::route('/'), 'create' => Pages\CreatePaymentMethod::route('/create'),
            'view' => Pages\ViewPaymentMethod::route('/{record}'), 'edit' => Pages\EditPaymentMethod::route('/{record}/edit')];
    }
}
