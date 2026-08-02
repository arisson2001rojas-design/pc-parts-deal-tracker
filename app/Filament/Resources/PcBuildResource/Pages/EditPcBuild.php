<?php

namespace App\Filament\Resources\PcBuildResource\Pages;

use App\Filament\Resources\PcBuildResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPcBuild extends EditRecord
{
    protected static string $resource = PcBuildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->refresh()->evaluateAlert();
    }
}
