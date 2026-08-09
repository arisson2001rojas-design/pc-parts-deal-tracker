<?php

namespace App\Filament\Resources\DealOfferResource\Columns;

use Filament\Tables\Columns\Column;
use Illuminate\Contracts\View\View;

class DealOfferCardColumn extends Column
{
    protected string $view = 'filament.resources.deal-offer-resource.columns.deal-offer-card';

    public function render(): View
    {
        $this->viewData(['offer' => $this->getRecord()]);

        return parent::render();
    }
}
