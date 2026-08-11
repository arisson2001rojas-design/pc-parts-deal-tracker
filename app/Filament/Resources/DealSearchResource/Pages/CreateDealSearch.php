<?php

namespace App\Filament\Resources\DealSearchResource\Pages;

use App\Filament\Resources\DealSearchResource;
use App\Jobs\RefreshDealSearchJob;
use Filament\Resources\Pages\CreateRecord;

class CreateDealSearch extends CreateRecord
{
    protected static string $resource = DealSearchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        RefreshDealSearchJob::dispatch($this->record->getKey());
    }
}
