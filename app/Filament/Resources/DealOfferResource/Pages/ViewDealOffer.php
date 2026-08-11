<?php

namespace App\Filament\Resources\DealOfferResource\Pages;

use App\Filament\Resources\DealOfferResource;
use App\Models\DealOffer;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/** @property DealOffer $record */
class ViewDealOffer extends ViewRecord
{
    protected static string $resource = DealOfferResource::class;

    protected static string $view = 'filament.resources.deal-offer-resource.pages.view';

    public function getTitle(): string|Htmlable
    {
        return 'Historial · '.Str::limit($this->record->dealSearch->name ?? $this->record->title, 64);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Volver al radar')
                ->icon('heroicon-m-arrow-left')
                ->color('gray')
                ->url(DealOfferResource::getUrl('index')),
            Action::make('retailer')
                ->label($this->record->supportsBrowserCapture() ? 'Abrir y verificar' : 'Abrir tienda')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->url($this->record->supportsBrowserCapture()
                    ? $this->record->browserCaptureLaunchUrl()
                    : $this->record->url)
                ->openUrlInNewTab(),
        ];
    }
}
