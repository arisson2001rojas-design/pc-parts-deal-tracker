<?php

namespace App\Filament\Resources\DealSearchResource\Pages;

use App\Filament\Resources\DealSearchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDealSearches extends ListRecords
{
    protected static string $resource = DealSearchResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New hunt')];
    }
}
