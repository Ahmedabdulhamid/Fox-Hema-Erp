<?php

namespace App\Modules\AccessManagement\Filament\Resources\Modules\Pages;

use App\Modules\AccessManagement\Filament\Resources\Modules\ModuleResource;
use App\Modules\AccessManagement\Services\AuditLogger;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditModule extends EditRecord
{
    protected static string $resource = ModuleResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(auth()->user()->can('update', $record), 403);

        return DB::transaction(function () use ($record, $data): Model {
            $record->update(Arr::only($data, ['sort_order', 'is_active']));
            app(AuditLogger::class)->record('module_updated', metadata: ['module_id' => $record->id, 'is_active' => $record->is_active]);

            return $record;
        });
    }
}
