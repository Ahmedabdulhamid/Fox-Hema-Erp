<?php

namespace App\Modules\Products\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MasterDetail
{
    /** The native schema context binds the action to the selected, parent-scoped row. */
    public static function entry(Closure $details): Actions
    {
        return Actions::make([self::action($details)])->key('line-details');
    }

    /** Details are evaluated when the action schema opens, not for each master-table row. */
    public static function action(Closure $details): Action
    {
        return Action::make('lineDetails')->label(__('View Details'))->icon('heroicon-o-eye')->link()->size('sm')
            ->modalHeading(__('View Details'))->slideOver()->modalWidth('xl')
            ->modalCloseButton()->closeModalByEscaping()->modalAutofocus()->stickyModalHeader()
            ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))->formWrapper(false)
            ->extraModalWindowAttributes(['class' => 'fox-detail-drawer'])
            ->schema(function (Schema $schema, Action $action) use ($details): Schema {
                $components = $action->evaluate($details, ['schema' => $schema]);

                if ($components instanceof Schema) {
                    foreach ($components->getComponents(withHidden: true) as $component) {
                        if ($component instanceof Section) {
                            $component->columns(['default' => 1, 'sm' => 2])->columnSpanFull();
                        }
                    }

                    return $components->columns(1);
                }

                return $schema->columns(1)->components($components ?: [TextEntry::make('empty')->hiddenLabel()->state(__('No records'))]);
            });
    }
}
