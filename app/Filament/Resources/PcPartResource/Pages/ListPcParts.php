<?php

namespace App\Filament\Resources\PcPartResource\Pages;

use App\Filament\Resources\PcPartResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPcParts extends ListRecords
{
    protected static string $resource = PcPartResource::class;

    public function getTabs(): array
    {
        return [
            'today' => Tab::make('Cheapest today')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereHas('currentUserProduct', fn (Builder $productQuery): Builder => $productQuery
                        ->where('current_price', '>', 0)
                        ->whereHas('prices', fn (Builder $priceQuery): Builder => $priceQuery
                            ->whereDate('prices.created_at', today())
                        )
                    )
                ),
            'tracked' => Tab::make('Tracked')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('currentUserProduct')),
            'all' => Tab::make('All catalog'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'today';
    }
}
