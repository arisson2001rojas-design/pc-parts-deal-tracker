<?php

namespace App\Filament\Resources\PcBuildResource\Pages;

use App\Filament\Resources\PcBuildResource;
use App\Models\PcBuild;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreatePcBuild extends CreateRecord
{
    protected static string $resource = PcBuildResource::class;

    protected static ?string $title = 'Crear armado de PC';

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Crear');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->label('Crear y añadir otro');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Cancelar');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var PcBuild $build */
        $build = $this->record;
        $build->refresh()->evaluateAlert();
    }
}
