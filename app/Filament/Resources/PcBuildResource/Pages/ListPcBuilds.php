<?php

namespace App\Filament\Resources\PcBuildResource\Pages;

use App\Filament\Resources\PcBuildResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPcBuilds extends ListRecords
{
    protected static string $resource = PcBuildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
