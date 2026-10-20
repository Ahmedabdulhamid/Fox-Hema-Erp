<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills\Tables;

use App\Models\MoneyFormatter;
use App\Modules\AccessManagement\Services\DocumentPresentation;
use App\Modules\Purchasing\Filament\BillMutation;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Filament\VendorBillActions;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\VendorBillService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class VendorBillsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_reference')->label(__('documents.erp_number'))
                ->searchable(query: fn ($query, string $search) => DocumentPresentation::searchNumber($query, $search)),
            TextColumn::make('bill_number')->label(__('Supplier invoice number'))->searchable()->sortable(),
            TextColumn::make('supplier.name')->label(__('Supplier'))->searchable()->sortable(),
            TextColumn::make('purchaseOrder.order_number')->label(__('Purchase Order'))->searchable()->sortable(),
            TextColumn::make('bill_date')->label(__('Bill date'))->date()->sortable(), TextColumn::make('due_date')->label(__('Due date'))->date()->sortable(),
            TextColumn::make('total_amount')->label(__('Bill total'))->formatStateUsing(fn ($state): string => MoneyFormatter::format($state))->sortable(),
            TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state): string => __(ucfirst($state)))->sortable(),
            TextColumn::make('created_at')->label(__('Created date'))->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->label(__('Status'))->options(['draft' => __('Draft'), 'posted' => __('Posted'), 'cancelled' => __('Cancelled')])])
            ->recordActions([ViewAction::make(), EditAction::make(), ...self::actions(), VendorBillActions::download('ar'), VendorBillActions::download('en')])->toolbarActions([])->defaultSort('id', 'desc');
    }

    public static function actions(): array
    {
        $actions = [];
        foreach (['post' => 'Post Bill', 'cancel' => 'Cancel Draft Bill'] as $method => $label) {
            $actions[] = Action::make($method)->label(__($label))->authorize($method)->visible(fn (VendorBill $record): bool => $record->status === 'draft')->requiresConfirmation()
                ->modalDescription(__('Applies to saved bill quantities and amounts. Posted bills cannot be edited or cancelled.'))
                ->schema([Hidden::make('revision')->default(fn (VendorBill $record): string => app(VendorBillService::class)->revision($record))])
                ->action(function (Action $action, VendorBill $record, array $data, Component $livewire) use ($method): void {
                    try {
                        BillMutation::run(fn () => app(VendorBillService::class)->{$method}(auth()->user(), $record, $data['revision']));
                    } catch (ValidationException $exception) {
                        $action->halt(true);
                    }
                    Notification::make()->title(__($method === 'post' ? 'Bill posted successfully' : 'Draft bill cancelled'))->success()->send();
                    $livewire->redirect(VendorBillResource::getUrl('view', ['record' => $record]));
                });
        }

        return $actions;
    }
}
