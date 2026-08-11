<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use Filament\Resources\Pages\ListRecords;

class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StoreResource\Actions\ImportStoreAction::make()->label('Importar'),
            StoreResource\Actions\CreateAction::make()->label('Añadir tienda')->color('primary'),
        ];
    }
}
