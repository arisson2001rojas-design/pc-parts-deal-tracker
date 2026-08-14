<?php

namespace App\Filament\Resources\DealOfferResource\Widgets;

use App\Models\DealOffer;
use App\Models\DealSearch;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class DealHunterStats extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $verified = DealOffer::query()
            ->currentUser()
            ->verifiedPrice()
            ->where('availability', '!=', DealOffer::AVAILABILITY_OUT_OF_STOCK)
            ->where('fetched_at', '>=', now()->subDays(7));

        $underTarget = (clone $verified)
            ->whereHas('dealSearch', fn (Builder $search): Builder => $search
                ->whereNotNull('target_price')
                ->whereColumn('deal_offers.price', '<=', 'deal_searches.target_price')
            )
            ->count();

        return [
            Stat::make('Precios verificados', (clone $verified)->count())
                ->description('Últimos 7 días')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success'),
            Stat::make('Bajo tu objetivo', $underTarget)
                ->description($underTarget === 1 ? 'Oportunidad activa' : 'Oportunidades activas')
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color($underTarget > 0 ? 'success' : 'gray'),
            Stat::make(
                'Cacerías activas',
                DealSearch::query()->currentUser()->where('enabled', true)->count(),
            )
                ->description('Se rastrean automáticamente')
                ->descriptionIcon('heroicon-m-magnifying-glass')
                ->color('primary'),
            Stat::make('Tiendas con precio', (clone $verified)->distinct('store')->count('store'))
                ->description('Fuentes verificadas')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('info'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
