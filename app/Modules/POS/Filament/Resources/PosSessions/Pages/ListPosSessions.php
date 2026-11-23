<?php

namespace App\Modules\POS\Filament\Resources\PosSessions\Pages;

use App\Modules\POS\Filament\Resources\PosSessions\PosSessionResource;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosShiftService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ListPosSessions extends ListRecords
{
    protected static string $resource = PosSessionResource::class;

    protected function getHeaderActions(): array
    {
        $locationId = (int) getPermissionsTeamId();
        if (app(PosAccess::class)->can(auth()->user(), PosPermissions::SHIFTS_OPEN)) {
            app(PosAccess::class)->authorize(auth()->user(), PosPermissions::SHIFTS_OPEN, $locationId);
        }

        return [
            Action::make('openShift')
                ->label(__('pos.actions.open_shift'))
                ->button()
                ->visible(fn (): bool => app(PosAccess::class)->can(auth()->user(), PosPermissions::SHIFTS_OPEN))
                ->schema([
                    Hidden::make('opening_key')->default(fn (): string => (string) Str::uuid7())->required(),
                    Select::make('pos_device_id')
                        ->label(__('pos.fields.device'))
                        ->options(fn (): array => PosDevice::query()
                            ->where('location_id', $locationId)
                            ->where('is_active', true)
                            ->whereNull('revoked_at')
                            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                                ->from('pos_sessions')
                                ->whereColumn('pos_sessions.pos_device_id', 'pos_devices.id')
                                ->whereIn('pos_sessions.status', ['open', 'closing']))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('cash_drawer_id', null))
                        ->required(),
                    Select::make('cash_drawer_id')
                        ->label(__('pos.fields.cash_drawer'))
                        ->options(fn (Get $get): array => filled($get('pos_device_id'))
                            ? app(PosShiftService::class)->cashDrawerOptions(auth()->user(), (int) $get('pos_device_id'))
                            : [])
                        ->required(),
                    TextInput::make('opening_cash')
                        ->label(__('pos.fields.opening_cash'))
                        ->inputMode('decimal')
                        ->default('0.0000')
                        ->required(),
                    Textarea::make('notes')->label(__('pos.fields.notes'))->maxLength(16000),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        Validator::make($data, [
                            'pos_device_id' => ['required', 'integer'],
                            'cash_drawer_id' => ['required', 'integer'],
                        ])->validate();
                        $key = (string) $data['opening_key'];
                        $session = app(PosShiftService::class)->open(
                            auth()->user(),
                            (int) $data['pos_device_id'],
                            (int) $data['cash_drawer_id'],
                            $data['opening_cash'],
                            $key,
                            $data['notes'] ?? null,
                        );
                        Notification::make()
                            ->title(__('pos.notifications.shift_opened', ['number' => $session->display_code]))
                            ->success()
                            ->send();
                        $this->redirect(PosSessionResource::getUrl('view', ['record' => $session]));
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(__('pos.notifications.shift_open_failed'))
                            ->body(collect($exception->errors())->flatten()->implode(' '))
                            ->danger()
                            ->persistent()
                            ->send();

                        throw $exception;
                    } catch (AuthorizationException|HttpException $exception) {
                        if ($exception instanceof HttpException && $exception->getStatusCode() !== 403) {
                            throw $exception;
                        }

                        $message = __('pos.validation.shift_open_unauthorized');
                        Notification::make()
                            ->title(__('pos.notifications.shift_open_failed'))
                            ->body($message)
                            ->danger()
                            ->persistent()
                            ->send();

                        throw ValidationException::withMessages(['shift' => $message]);
                    } catch (Throwable $exception) {
                        report($exception);
                        $message = __('pos.validation.shift_open_failed');
                        Notification::make()
                            ->title(__('pos.notifications.shift_open_failed'))
                            ->body($message)
                            ->danger()
                            ->persistent()
                            ->send();

                        throw ValidationException::withMessages(['shift' => $message]);
                    }
                }),
        ];
    }
}
