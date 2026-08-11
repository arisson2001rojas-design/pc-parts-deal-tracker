<?php

namespace App\Filament\Resources\PcBuildResource\Pages;

use App\Filament\Resources\PcBuildResource;
use App\Models\PcBuild;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditPcBuild extends EditRecord
{
    protected static string $resource = PcBuildResource::class;

    protected static ?string $title = 'Editar armado de PC';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Eliminar'),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Guardar');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Cancelar');
    }

    protected function afterSave(): void
    {
        /** @var PcBuild $build */
        $build = $this->record;
        $build->refresh()->evaluateAlert();
    }
}
