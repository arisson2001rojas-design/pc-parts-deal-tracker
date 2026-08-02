<?php

namespace App\Filament\Resources\PcBuildResource\Pages;

use App\Filament\Resources\PcBuildResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePcBuild extends CreateRecord
{
    protected static string $resource = PcBuildResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->refresh()->evaluateAlert();
    }
}
