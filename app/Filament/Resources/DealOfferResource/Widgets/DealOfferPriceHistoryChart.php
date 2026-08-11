<?php

namespace App\Filament\Resources\DealOfferResource\Widgets;

use App\Models\DealOffer;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Widgets\ChartWidget;

class DealOfferPriceHistoryChart extends ChartWidget
{
    public ?DealOffer $record = null;

    protected static ?string $heading = 'Historial verificado por PriceBuddy';

    protected static ?string $maxHeight = '320px';

    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $history = $this->record?->priceSnapshots()
            ->orderBy('captured_at')
            ->get() ?? collect();
        $labels = $history
            ->map(fn ($snapshot): string => $snapshot->captured_at->format('M j · H:i'))
            ->all();

        $datasets = [[
            'label' => 'Precio verificado (USD)',
            'data' => $history->map(fn ($snapshot): float => (float) $snapshot->price)->all(),
            'borderColor' => 'rgba('.AdminPanelProvider::PRIMARY_COLOR[500].', 1)',
            'backgroundColor' => 'rgba('.AdminPanelProvider::PRIMARY_COLOR[500].', 0.18)',
            'borderWidth' => 3,
            'pointRadius' => 4,
            'pointHoverRadius' => 6,
            'fill' => true,
            'tension' => 0.25,
        ]];

        $target = $this->record?->dealSearch?->target_price;
        if ($target !== null && $labels !== []) {
            $datasets[] = [
                'label' => 'Tu objetivo',
                'data' => array_fill(0, count($labels), (float) $target),
                'borderColor' => 'rgba(244, 63, 94, 0.9)',
                'backgroundColor' => 'rgba(0, 0, 0, 0)',
                'borderWidth' => 2,
                'borderDash' => [6, 6],
                'pointRadius' => 0,
                'fill' => false,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['intersect' => false, 'mode' => 'index'],
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'y' => ['beginAtZero' => false],
                'x' => ['grid' => ['display' => false]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
