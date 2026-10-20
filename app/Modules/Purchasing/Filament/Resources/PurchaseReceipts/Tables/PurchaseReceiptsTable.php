<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Tables;

use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class PurchaseReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('receipt_number')->label(__('Receipt number'))->formatStateUsing(fn ($record): string => $record->display_reference)->searchable()->sortable(),
            TextColumn::make('purchaseOrder.order_number')->label(__('PO number'))->searchable()->sortable(),
            TextColumn::make('purchaseOrder.supplier.name')->label(__('Supplier'))->searchable(),
            TextColumn::make('location.name')->label(__('Destination location'))->sortable(),
            TextColumn::make('status')->label(__('Status'))->badge()->sortable()->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextColumn::make('received_at')->label(__('Received date'))->dateTime()->sortable(),
            TextColumn::make('created_at')->label(__('Created date'))->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->label(__('Status'))->options(['draft' => __('Draft'), 'posted' => __('Posted'), 'cancelled' => __('Cancelled')]),
            SelectFilter::make('location_id')->label(__('Destination location'))->options(fn (): array => app(PurchasingAccess::class)->receiptLocations(auth()->user())->pluck('name', 'id')->all()),
            SelectFilter::make('purchase_order_id')->label(__('PO number'))->searchable()->options(fn (): array => app(PurchasingAccess::class)
                ->scopeReceipts(PurchaseReceipt::query(), auth()->user())->with('purchaseOrder')->get()->pluck('purchaseOrder.order_number', 'purchase_order_id')->all()),
            SelectFilter::make('supplier_id')->label(__('Supplier'))->searchable()
                ->options(fn (): array => app(PurchasingAccess::class)->scopeReceipts(PurchaseReceipt::query(), auth()->user())
                    ->with('purchaseOrder.supplier')->get()->pluck('purchaseOrder.supplier.name', 'purchaseOrder.supplier_id')->all())
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                    fn ($query, $id) => $query->whereHas('purchaseOrder', fn ($query) => $query->where('supplier_id', $id)))),
            PurchaseOrdersTable::dates('received_at')->label(__('Received dates')),
        ])->recordActions([ViewAction::make(), EditAction::make(), ...self::actions()])
            ->toolbarActions([])->defaultSort('id', 'desc')->paginationPageOptions([25, 50, 100]);
    }

    public static function actions(): array
    {
        $actions = [];
        foreach (['post' => 'Validate Receipt', 'cancel' => 'Cancel Draft Receipt'] as $method => $label) {
            $actions[] = Action::make($method)->label(__($label))->authorize($method)
                ->visible(fn (PurchaseReceipt $record): bool => $record->status === 'draft')->requiresConfirmation()
                ->modalDescription(__('Applies to saved receipt quantities and costs.').($method === 'post' ? ' '.__('Enter actual received quantities and save the draft before validating.') : ''))
                ->schema([Hidden::make('revision')->default(fn (PurchaseReceipt $record): string => app(PurchaseReceiptService::class)->revision($record))])
                ->action(function (Action $action, PurchaseReceipt $record, array $data, Component $livewire) use ($method): void {
                    $errorTitle = __($method === 'post' ? 'Cannot validate receipt' : 'Cannot cancel receipt');
                    try {
                        app(PurchaseReceiptService::class)->{$method}(auth()->user(), $record, $data['revision']);
                    } catch (ValidationException $exception) {
                        Notification::make()->title($errorTitle)
                            ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                        $action->halt(true);
                    } catch (AuthorizationException $exception) {
                        Notification::make()->title($errorTitle)->body(__('You are not authorized to perform this receipt operation.'))->danger()->send();
                        $action->halt(true);
                    } catch (HttpException $exception) {
                        if ($exception->getStatusCode() !== 403) {
                            report($exception);
                        }
                        Notification::make()->title($errorTitle)->body(__($exception->getStatusCode() === 403
                            ? 'You are not authorized to perform this receipt operation.'
                            : 'The receipt operation failed. Refresh the page and try again. If the problem continues, contact your administrator.'))->danger()->send();
                        $action->halt(true);
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title($errorTitle)
                            ->body(__('The receipt operation failed. Refresh the page and try again. If the problem continues, contact your administrator.'))->danger()->send();
                        $action->halt(true);
                    }
                    Notification::make()->title(__($method === 'post' ? 'Receipt posted successfully' : 'Draft receipt cancelled'))->success()->send();
                    $livewire->redirect(PurchaseReceiptResource::getUrl('view', ['record' => $record]));
                });
        }
        $actions[] = Action::make('purchaseOrder')->label(__('View Purchase Order'))
            ->visible(fn (PurchaseReceipt $record): bool => Gate::allows('view', $record->purchaseOrder))
            ->url(fn (PurchaseReceipt $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record->purchaseOrder]));

        return $actions;
    }
}
