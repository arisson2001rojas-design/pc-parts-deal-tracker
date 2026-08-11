<?php

namespace App\Filament\Resources\DealSearchResource\Pages;

use App\Filament\Resources\DealSearchResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDealSearch extends EditRecord
{
    protected static string $resource = DealSearchResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
