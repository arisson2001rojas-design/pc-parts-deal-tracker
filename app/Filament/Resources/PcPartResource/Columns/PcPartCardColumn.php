<?php

namespace App\Filament\Resources\PcPartResource\Columns;

use Filament\Tables\Columns\Column;
use Illuminate\Contracts\View\View;

class PcPartCardColumn extends Column
{
    protected string $view = 'filament.resources.pc-part-resource.columns.pc-part-card';

    public function render(): View
    {
        $this->viewData(['part' => $this->getRecord()]);

        return parent::render();
    }
}
